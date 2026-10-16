<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\GameObjects\GameObjectRegistry;
use App\Libraries\Functions;
use App\Services\FormatService;
use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\BuildingsEnumerator as Buildings;

/**
 * The jump gate: moves ships between two moons of a player that both have
 * a gate; afterwards both gates recharge for an hour divided by their level.
 *
 * @phpstan-type JumpGateSection array{
 *     action: string,
 *     startMoonLink: string,
 *     destinations: array<int, array{id: int, label: string}>,
 *     ships: array<int, array{id: int, name: string, max: string, availability: string, tabIndex: int}>,
 *     countdownScriptHead: string,
 *     waitTimeHtml: string,
 *     countdownScriptTail: string,
 * }
 */
class JumpGateService
{
    use PreparesLegacySql;
    use ReadsMixedValues;

    private const GATE = 'building_jump_gate';

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    public function __construct(
        private GameObjectRegistry $registry,
        private FormatService $formatService,
    ) {
    }

    /**
     * The jump gate part of the technology details of a moon with a gate.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     *
     * @return JumpGateSection|null
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function section(array $user, array $planet): ?array
    {
        $this->user = $user;
        $this->planet = $planet;

        if (self::asInt($this->planet[self::GATE] ?? 0) <= 0) {
            return null;
        }

        $restString = $this->getNextJumpWaitTime($this->planet);

        return [
            'action' => 'game.php?page=technologydetails&technology=' . Buildings::BUILDING_JUMP_GATE,
            'startMoonLink' => $this->planetLink($this->planet),
            'destinations' => $this->buildJumpGateDestinations(),
            'ships' => $this->buildJumpGateShips(),
            'countdownScriptHead' => $restString['value'] !== 0 ? Functions::chronoApplet('Gate', '1', $restString['value'], true) : '',
            'waitTimeHtml' => $restString['value'] !== 0 ? '<div id="bxxGate1"></div>' : '',
            'countdownScriptTail' => $restString['value'] !== 0 ? Functions::chronoApplet('Gate', '1', $restString['value'], false) : '',
        ];
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    private function buildJumpGateDestinations(): array
    {
        $moonList = array_map(
            fn ($row) => (array) $row,
            DB::select(
                $this->prepareSql(
                    'SELECT
                        m.`planet_id`,
                        m.`planet_galaxy`,
                        m.`planet_system`,
                        m.`planet_planet`,
                        m.`planet_name`,
                        m.`planet_last_jump_time`,
                        b.`building_jump_gate`
                    FROM `' . PLANETS . '` AS m
                    INNER JOIN `' . BUILDINGS . "` AS b ON b.building_planet_id = m.planet_id
                    WHERE m.`planet_type` = '3'
                        AND m.`planet_user_id` = '" . (self::asInt($this->user['id'])) . "';"
                )
            )
        );

        $destinations = [];

        foreach ($moonList as $currentMoon) {
            if (self::asInt($currentMoon['planet_id'] ?? 0) === self::asInt($this->planet['planet_id'] ?? 0)) {
                continue;
            }

            if ((int) ($currentMoon[self::GATE] ?? 0) < 1) {
                continue;
            }

            $restString = $this->getNextJumpWaitTime($currentMoon);
            $destinations[] = [
                'id' => (int) $currentMoon['planet_id'],
                'label' => '[' . $currentMoon['planet_galaxy'] . ':' . $currentMoon['planet_system'] . ':' . $currentMoon['planet_planet'] . '] ' . $currentMoon['planet_name'] . $restString['string'],
            ];
        }

        return $destinations;
    }

    /**
     * @return array<int, array{id: int, name: string, max: string, availability: string, tabIndex: int}>
     */
    private function buildJumpGateShips(): array
    {
        $ships = [];
        $currentIndex = 1;

        for ($ship = 200; $ship < 250; $ship++) {
            $resourceKey = $this->registry->has($ship) ? $this->registry->get($ship)->getName() : '';

            if ($resourceKey === '' || self::asInt($this->planet[$resourceKey] ?? 0) <= 0) {
                continue;
            }

            $ships[] = [
                'id' => $ship,
                'name' => (string) __('game/ships.' . $resourceKey),
                'max' => $this->formatService->prettyNumber(self::asInt($this->planet[$resourceKey] ?? 0)),
                'availability' => __('game/technologydetails.in_jump_gate_available'),
                'tabIndex' => $currentIndex,
            ];
            $currentIndex++;
        }

        return $ships;
    }

    /**
     * @param array<string, mixed> $moon
     *
     * @return array{string: string, value: int}
     */
    private function getNextJumpWaitTime(array $moon): array
    {
        $jumpGateLevel = self::asInt($moon[self::GATE] ?? 0);
        $lastJumpTime = self::asInt($moon['planet_last_jump_time'] ?? 0);

        if ($jumpGateLevel <= 0) {
            return ['string' => '', 'value' => 0];
        }

        $waitBetweenJump = intdiv(60 * 60, $jumpGateLevel);
        $nextJumpTime = $lastJumpTime + $waitBetweenJump;

        if ($nextJumpTime < time()) {
            return ['string' => '', 'value' => 0];
        }

        $restWait = $nextJumpTime - time();

        return [
            'string' => ' ' . $this->formatService->prettyTime($restWait),
            'value' => $restWait,
        ];
    }

    /**
     * @param array<int, int> $ships
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     *
     * @return array{success: bool, message: string}
     */
    public function jump(array $user, array $planet, int $targetPlanet, array $ships): array
    {
        $this->user = $user;
        $this->planet = $planet;

        $targetGate = $this->readyTargetGate($targetPlanet);

        if (is_string($targetGate)) {
            return ['success' => false, 'message' => $targetGate];
        }

        $moves = $this->shipMoves($ships);

        if ($moves === []) {
            return ['success' => false, 'message' => self::asString(__('game/technologydetails.in_jump_gate_error_data'))];
        }

        $this->planet['planet_last_jump_time'] = $this->transfer($moves, self::asInt($targetGate['planet_id'] ?? 0));
        $restString = $this->getNextJumpWaitTime($this->planet);

        return [
            'success' => true,
            'message' => self::asString(__('game/technologydetails.in_jump_gate_done')) . $restString['string'],
        ];
    }

    /**
     * The target moon's gate, when both gates are ready; else why not.
     *
     * @return array<string, mixed>|string
     */
    private function readyTargetGate(int $targetPlanet): array | string
    {
        if (self::asInt($this->planet[self::GATE] ?? 0) <= 0) {
            return self::asString(__('game/technologydetails.in_jump_gate_error_data'));
        }

        $restString = $this->getNextJumpWaitTime($this->planet);

        if ($restString['value'] !== 0) {
            return self::asString(__('game/technologydetails.in_jump_gate_already_used')) . $restString['string'];
        }

        $targetGate = $this->findJumpGateDestination($targetPlanet);

        if ($targetGate === null) {
            return self::asString(__('game/technologydetails.in_jump_gate_doesnt_have_one'));
        }

        $restString = $this->getNextJumpWaitTime($targetGate);

        if ($restString['value'] !== 0) {
            return self::asString(__('game/technologydetails.in_jump_gate_not_ready_target')) . $restString['string'];
        }

        return $targetGate;
    }

    /**
     * The ships to move, at most as many as the moon has: column => count.
     *
     * @param array<int, int> $ships
     *
     * @return array<string, int>
     */
    private function shipMoves(array $ships): array
    {
        $moves = [];

        foreach ($ships as $ship => $shipCount) {
            if ($ship < 200 || $ship >= 300 || !$this->registry->has($ship)) {
                continue;
            }

            $resourceKey = $this->registry->get($ship)->getName();
            $shipsToMove = min(max(0, $shipCount), self::asInt($this->planet[$resourceKey] ?? 0));

            if ($resourceKey !== '' && $shipsToMove > 0) {
                $moves[$resourceKey] = $shipsToMove;
            }
        }

        return $moves;
    }

    /**
     * Move the ships and the player to the target moon and start both gates'
     * recharge; returns the jump time.
     *
     * @param array<string, int> $moves
     */
    private function transfer(array $moves, int $targetId): int
    {
        $subQueryOrigin = '';
        $subQueryDestination = '';

        foreach ($moves as $resourceKey => $shipsToMove) {
            $subQueryOrigin .= '`' . $resourceKey . '` = `' . $resourceKey . "` - '" . $shipsToMove . "', ";
            $subQueryDestination .= '`' . $resourceKey . '` = `' . $resourceKey . "` + '" . $shipsToMove . "', ";
        }

        $jumpTime = time();
        $originId = self::asInt($this->planet['planet_id'] ?? 0);
        $userId = self::asInt($this->user['id'] ?? 0);

        DB::transaction(function () use ($subQueryOrigin, $subQueryDestination, $jumpTime, $targetId, $originId, $userId): void {
            DB::statement(
                $this->prepareSql(
                    'UPDATE `' . PLANETS . '`, `' . USERS . '`, `' . SHIPS . "` SET
                        $subQueryOrigin
                        `planet_last_jump_time` = '" . $jumpTime . "',
                        `current_planet` = '" . $targetId . "'
                    WHERE `planet_id` = '" . $originId . "'
                        AND `ship_planet_id` = '" . $originId . "'
                        AND `id` = '" . $userId . "';"
                )
            );

            DB::statement(
                $this->prepareSql(
                    'UPDATE `' . PLANETS . '`, `' . SHIPS . "` SET
                        $subQueryDestination
                        `planet_last_jump_time` = '" . $jumpTime . "'
                    WHERE `planet_id` = '" . $targetId . "'
                        AND `ship_planet_id` = '" . $targetId . "';"
                )
            );
        });

        return $jumpTime;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findJumpGateDestination(int $targetPlanet): ?array
    {
        if (
            $targetPlanet <= 0 ||
            $targetPlanet === self::asInt($this->planet['planet_id'] ?? 0)
        ) {
            return null;
        }

        $gateRow = DB::selectOne(
            $this->prepareSql(
                'SELECT
                    p.`planet_id`,
                    b.`building_jump_gate`,
                    p.`planet_last_jump_time`
                FROM `' . PLANETS . '` AS p
                INNER JOIN `' . BUILDINGS . "` AS b
                    ON b.`building_planet_id` = p.`planet_id`
                WHERE p.`planet_id` = '" . $targetPlanet . "'
                    AND p.`planet_type` = '3'
                    AND p.`planet_user_id` = '" . (self::asInt($this->user['id'] ?? 0)) . "';"
            )
        );

        if ($gateRow === null) {
            return null;
        }

        /** @var array<string, mixed> $targetGate */
        $targetGate = (array) $gateRow;

        return self::asInt($targetGate['building_jump_gate'] ?? 0) > 0 ? $targetGate : null;
    }

    /**
     * @param array<string, mixed> $currentPlanet
     */
    private function planetLink(array $currentPlanet): string
    {
        $galaxy = self::asInt($currentPlanet['planet_galaxy'] ?? 0);
        $system = self::asInt($currentPlanet['planet_system'] ?? 0);
        $planet = self::asInt($currentPlanet['planet_planet'] ?? 0);

        return '<a href="game.php?page=galaxy&mode=3&galaxy=' . $galaxy . '&system=' . $system . '">[' . $galaxy . ':' . $system . ':' . $planet . ']</a>';
    }
}
