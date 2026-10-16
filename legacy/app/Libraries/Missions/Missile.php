<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

use App\Services\FormatService;
use Xgp\App\Core\Objects;
use App\Libraries\Functions;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Missile extends Missions
{
    public function __construct(private FormatService $formatService)
    {
        parent::__construct();
    }

    /**
     * Interplanetary missile attack on the defenses of the target planet.
     *
     * @param array<string, mixed> $fleetRow
     */
    public function missileMission(array $fleetRow): void
    {
        if (!parent::canStartMission($fleetRow)) {
            return;
        }

        $attackerData = $this->getMissileAttackerDataByCoords([
            'coords' => [
                'galaxy' => $fleetRow['fleet_start_galaxy'],
                'system' => $fleetRow['fleet_start_system'],
                'planet' => $fleetRow['fleet_start_planet'],
                'type' => $fleetRow['fleet_start_type'],
            ],
        ]);

        $targetData = $this->getMissileTargetDataByCoords([
            'coords' => [
                'galaxy' => $fleetRow['fleet_end_galaxy'],
                'system' => $fleetRow['fleet_end_system'],
                'planet' => $fleetRow['fleet_end_planet'],
                'type' => $fleetRow['fleet_end_type'],
            ],
        ]);

        $missiles = self::int($fleetRow['fleet_amount']);
        $antiBallistic = self::int($targetData['defense_anti-ballistic_missile']);

        [$message, $result] = $antiBallistic >= $missiles
            ? $this->interceptAll($targetData, $missiles, $antiBallistic)
            : $this->penetrateDefenses($fleetRow, $attackerData, $targetData, $missiles, $antiBallistic);

        if (count($result) > 0) {
            $message .= $this->defenseReport($targetData, $result);
        }

        if (empty($message)) {
            $message = self::text(__('game/missile.mis_planet_without_defenses'));
        }

        $this->sendResults($fleetRow, $attackerData, $targetData, $message);

        parent::removeFleet(self::int($fleetRow['fleet_id']));
    }

    /**
     * At least as many anti-ballistic missiles as missiles: every missile is
     * intercepted, one interceptor each.
     *
     * Bugfix: the interceptors used to stay on the planet, so a stock of them
     * stopped any number of attacks.
     *
     * @param array<string, mixed> $targetData
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function interceptAll(array $targetData, int $missiles, int $antiBallistic): array
    {
        $this->updatePlanetDefenses([
            'destroyed_query' => '',
            'amount' => $antiBallistic - $missiles,
            'planet_id' => $targetData['planet_id'],
        ]);

        return [self::text(__('game/missile.mis_all_destroyed')) . '<br>', []];
    }

    /**
     * More missiles than anti-ballistic missiles: the interceptors are used up and
     * the remaining missiles damage the defenses.
     *
     * @param array<string, mixed> $fleetRow
     * @param array<string, mixed> $attackerData
     * @param array<string, mixed> $targetData
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function penetrateDefenses(array $fleetRow, array $attackerData, array $targetData, int $missiles, int $antiBallistic): array
    {
        $message = '';
        $result = [];

        if ($antiBallistic > 0) {
            $result[502] = $antiBallistic;
            $message = $antiBallistic . self::text(__('game/missile.mis_some_destroyed')) . ' <br>';
        }

        $attack = floor(
            ($missiles - $antiBallistic) * (self::num($this->combatCaps[503]['attack']) * (1 + (self::num($attackerData['research_weapons_technology']) / 10)))
        );
        $impact = $this->impactDefenses($attack, $this->setAttackOrder(self::int($fleetRow['fleet_target_obj'])), $targetData);
        $result += $impact['destroyed'];

        if ($impact['query'] != '') {
            $this->updatePlanetDefenses([
                'destroyed_query' => $impact['query'],
                'amount' => 0,
                'planet_id' => $targetData['planet_id'],
            ]);
        }

        return [$message, $result];
    }

    /**
     * Spend the missiles' attack power on the target defenses in attack order.
     *
     * @param list<int>            $attackOrder
     * @param array<string, mixed> $targetData
     *
     * @return array{destroyed: array<int, mixed>, query: string}
     */
    private function impactDefenses(float $attack, array $attackOrder, array $targetData): array
    {
        $destroyedUnits = [];
        $query = '';
        $shielding = 1 + (self::num($targetData['research_shielding_technology']) / 10);

        foreach ($attackOrder as $defenseId) {
            $column = $this->resource[$defenseId];
            $available = $targetData[$column];

            if (!$available) {
                continue;
            }

            $defense = ((self::num($this->pricelist[$defenseId]['metal']) + self::num($this->pricelist[$defenseId]['crystal'])) / 10) * $shielding;
            $destroyed = $attack >= ($defense * self::num($available)) ? $available : floor($attack / $defense);
            $attack -= self::num($destroyed) * $defense;

            if ($destroyed != 0) {
                $destroyedUnits[$defenseId] = $destroyed;
                $query .= '`' . $column . '` = `' . $column . '` - ' . self::str($destroyed) . ',';
            }
        }

        return ['destroyed' => $destroyedUnits, 'query' => $query];
    }

    /**
     * Remaining defenses of the target, with the losses of this attack.
     *
     * @param array<string, mixed> $targetData
     * @param array<int, mixed>    $result
     */
    private function defenseReport(array $targetData, array $result): string
    {
        $report = '';
        $defenses = Objects::getInstance()->getObjectsList('defense');

        foreach ($defenses as $defenseId) {
            $column = $this->resource[$defenseId];
            $report .= $this->formatService->prettyNumber(self::int($targetData[$column])) . ' ' . self::text(__('game/defenses.' . $column));

            if (isset($result[$defenseId])) {
                $report .= ' (-' . $this->formatService->prettyNumber(self::int($result[$defenseId])) . ')';
            }

            $report .= '<br>';
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $fleetRow
     * @param array<string, mixed> $attackerData
     * @param array<string, mixed> $targetData
     */
    private function sendResults(array $fleetRow, array $attackerData, array $targetData, string $message): void
    {
        $single = $fleetRow['fleet_amount'] == 1 ? '_single' : '';
        $search = ['%1%', '%2%', '%3%'];
        $replace = [
            self::str($fleetRow['fleet_amount']),
            self::str($attackerData['planet_name']) . ' ' . $this->formatService->prettyCoords(
                self::int($fleetRow['fleet_start_galaxy']),
                self::int($fleetRow['fleet_start_system']),
                self::int($fleetRow['fleet_start_planet'])
            ),
            self::str($targetData['planet_name']) . ' ' . $this->formatService->prettyCoords(
                self::int($fleetRow['fleet_end_galaxy']),
                self::int($fleetRow['fleet_end_system']),
                self::int($fleetRow['fleet_end_planet'])
            ),
        ];

        // attacker
        Functions::sendMessage(
            self::int($fleetRow['fleet_owner']),
            0,
            self::int($fleetRow['fleet_end_time']),
            5,
            __('game/missions.mi_fleet_command'),
            __('game/missile.mis_attack'),
            str_replace($search, $replace, self::text(__('game/missile.mis_result_own' . $single))) . $message
        );

        // enemy
        Functions::sendMessage(
            self::int($targetData['planet_user_id']),
            0,
            self::int($fleetRow['fleet_end_time']),
            5,
            __('game/missions.mi_fleet_command'),
            __('game/missile.mis_attack'),
            str_replace($search, $replace, self::text(__('game/missile.mis_result' . $single))) . $message
        );
    }

    /**
     * @return list<int>
     */
    private function setAttackOrder(int $primaryObjective): array
    {
        $objectives = [
            0 => [401, 402, 403, 404, 405, 406, 407, 408, 503],
            1 => [401, 402, 403, 404, 405, 406, 407, 408, 503],
            2 => [402, 401, 403, 404, 405, 406, 407, 408, 503],
            3 => [403, 401, 402, 404, 405, 406, 407, 408, 503],
            4 => [404, 401, 402, 403, 405, 406, 407, 408, 503],
            5 => [405, 401, 402, 403, 404, 406, 407, 408, 503],
            6 => [406, 401, 402, 403, 404, 405, 407, 408, 503],
            7 => [407, 401, 402, 403, 404, 405, 406, 408, 503],
            8 => [408, 401, 402, 403, 404, 405, 406, 407, 503],
        ];

        return $objectives[$primaryObjective];
    }

    /**
     * A translation as string (translations of whole groups come back as arrays).
     */
    private static function text(mixed $translation): string
    {
        return is_string($translation) ? $translation : '';
    }
}
