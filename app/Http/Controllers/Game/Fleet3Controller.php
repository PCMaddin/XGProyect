<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\FleetsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator as PlanetTypes;
use Xgp\App\Core\Enumerators\ShipsEnumerator as Ships;
use Xgp\App\Core\Objects;
use Xgp\App\Libraries\Functions;
use App\Libraries\Research\Researches;
use Xgp\App\Libraries\Users;

/**
 * Fleet wizard step 3: pick the mission for the selected ships and target.
 * Works out which missions the ship mix + target combination allows, computes
 * the flight distance/consumption, and stores it in the session for step 4.
 * Any invalid state bails back to step 1.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 */
class Fleet3Controller extends BaseController
{
    use PreparesLegacySql;

    public const REDIRECT_TARGET = 'game.php?page=fleet1';

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    private Researches $research;

    private Objects $objects;

    private int $currentMission = 0;

    /** @var array<int, int> */
    private array $allowedMissions = [];

    public function __construct(
        private FormatService $formatService,
        private FleetsService $fleetsService,
    ) {
    }

    public function __invoke(Request $request): View
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Fleet));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();
        $this->objects = new Objects();

        $this->research = new Researches([$this->user]);

        return $this->buildPage($request);
    }

    private function buildPage(Request $request): View
    {
        $inputsData = $this->setInputsData($request);
        $fleetBlock = $this->buildFleetBlock();
        $title = $this->buildTitleBlock();
        $missionSelector = $this->buildMissionBlock();
        $stayBlock = $this->buildStayBlock();

        return view('fleet.fleet3_view', array_merge(
            [
                'fleet_block' => $fleetBlock,
                'title' => $title,
                'mission_selector' => $missionSelector,
                'stay_block' => $stayBlock,
            ],
            $inputsData
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFleetBlock(): array
    {
        $objects = $this->objects->getObjects();
        $planetId = $this->planetInt('planet_id');

        $shipsRow = $planetId > 0 ? DB::selectOne(
            $this->prepareSql(
                'SELECT
                    s.`ship_small_cargo_ship`, s.`ship_big_cargo_ship`, s.`ship_light_fighter`,
                    s.`ship_heavy_fighter`, s.`ship_cruiser`, s.`ship_battleship`, s.`ship_colony_ship`,
                    s.`ship_recycler`, s.`ship_espionage_probe`, s.`ship_bomber`, s.`ship_solar_satellite`,
                    s.`ship_destroyer`, s.`ship_deathstar`, s.`ship_reaper`
                FROM `' . SHIPS . '` AS s
                WHERE s.`ship_planet_id` = ?;'
            ),
            [$planetId]
        ) : null;

        if (!is_object($shipsRow)) {
            return [];
        }

        $selected = $this->sessionShips();
        $list = [];

        foreach (get_object_vars($shipsRow) as $shipName => $rawAvailable) {
            $available = $this->asInt($rawAvailable);

            if ($available === 0 || !is_string($shipName)) {
                continue;
            }

            $shipId = is_array($objects) ? $this->asInt(array_search($shipName, $objects, true)) : 0;
            $requested = $this->asInt($selected[$shipId] ?? 0);

            if ($requested === 0) {
                continue;
            }

            $list[] = [
                'ship_id' => $shipId,
                'consumption' => $this->fleetsService->shipConsumption($shipId, $this->driveLevel('combustion'), $this->driveLevel('impulse'), $this->driveLevel('hyperspace')),
                'speed' => $this->fleetsService->getShipSpeed($shipId, $this->driveLevel('combustion'), $this->driveLevel('impulse'), $this->driveLevel('hyperspace')),
                'capacity' => $this->fleetsService->getMaxStorage(
                    $this->asInt($this->objects->getPrice($shipId, 'capacity')),
                    $this->research->getCurrentResearch()->getResearchHyperspaceTechnology()
                ),
                'ship' => min($requested, $available),
            ];
        }

        return $list;
    }

    private function buildTitleBlock(): string
    {
        return $this->formatService->prettyCoords(
            $this->planetInt('planet_galaxy'),
            $this->planetInt('planet_system'),
            $this->planetInt('planet_planet')
        ) . ' - ' . $this->planetTypeName($this->planetInt('planet_type'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMissionBlock(): array
    {
        $missionsList = $this->getAllowedMissions();

        if ($this->currentMission === 0) {
            $this->currentMission = $missionsList[0] ?? 0;
        }

        $selector = [];

        foreach ($missionsList as $mission) {
            $selector[] = [
                'value' => $mission,
                'mission' => $this->missionName($mission),
                'expedition_message' => $mission === Missions::EXPEDITION ? (string) __('game/fleet.fl_expedition_alert_message') : '',
                'id' => $mission === Missions::EXPEDITION ? ' ' : 'inpuT_' . $mission,
                'checked' => $mission === $this->currentMission ? ' checked="checked"' : '',
            ];
        }

        return $selector;
    }

    private function buildStayBlock(): string
    {
        // by rule, expedition time is based on the astrophysics level, 1:1 level:hour
        $maxExpTime = $this->research->getCurrentResearch()->getResearchAstrophysics();
        $hours = [0, 1, 2, 4, 8, 16, 32];
        $options = [];
        $stayType = '';

        if (in_array(Missions::EXPEDITION, $this->allowedMissions, true)) {
            $stayType = 'expeditiontime';

            for ($i = 1; $i <= $maxExpTime; $i++) {
                $options[] = ['value' => $i, 'selected' => $i === 1 ? ' selected' : ''];
            }
        }

        if (in_array(Missions::STAY, $this->allowedMissions, true)) {
            $stayType = 'holdingtime';

            foreach ($hours as $hour) {
                $options[] = ['value' => $hour, 'selected' => $hour === 1 ? ' selected' : ''];
            }
        }

        return $options === []
            ? ''
            : view('fleet.fleet3_stay_row', ['stay_type' => $stayType, 'options' => $options])->render();
    }

    /**
     * @return array<int, int>
     */
    private function getAllowedMissions(): array
    {
        $ships = $this->sessionShips();
        $target = $this->sessionTarget();
        $possible = $this->possibleMissionsForTarget($target);

        $missions = [];

        foreach ($ships as $shipId => $amount) {
            if ($this->asInt($amount) > 0) {
                $missions[] = array_intersect($this->shipMissionRules()[$shipId] ?? [], $possible);
            }
        }

        $set = $missions === [] ? [] : array_values(array_unique(array_merge(...$missions)));
        sort($set);

        if ($set === []) {
            $this->redirectToStart();
        }

        $this->allowedMissions = $set;

        return $set;
    }

    /**
     * Missions allowed by the target planet/relationship, before ship filtering.
     *
     * @param  array<string, mixed>  $target
     * @return array<int, int>
     */
    private function possibleMissionsForTarget(array $target): array
    {
        $galaxy = $this->asInt($target['galaxy'] ?? 0);
        $system = $this->asInt($target['system'] ?? 0);
        $planet = $this->asInt($target['planet'] ?? 0);
        $type = $this->asInt($target['type'] ?? 0);

        if ($planet === MAX_PLANET_IN_SYSTEM + 1) {
            return [Missions::EXPEDITION];
        }

        $selectedPlanet = $this->fetchTargetPlanet($galaxy, $system, $planet, $type);
        $occupied = $selectedPlanet !== [];
        $isOwn = $occupied && $this->asInt($selectedPlanet['planet_user_id'] ?? 0) === $this->userInt('id');

        $possible = $this->targetMissionRules()[$type][$isOwn ? 'own' : 'other'] ?? [];

        if ($this->acsGroupCount($this->asInt($target['group'] ?? 0)) === 0) {
            $possible = $this->without($possible, Missions::ACS);
        }

        if ($occupied && !$this->isFriendly($selectedPlanet)) {
            $possible = $this->without($possible, Missions::STAY);
        }

        if ($occupied) {
            $possible = $this->without($possible, Missions::COLONIZE);
        }

        return $possible;
    }

    /**
     * @return array<string, int|string>
     */
    private function setInputsData(Request $request): array
    {
        $data = $this->validatedTarget($request);

        $this->currentMission = $this->asInt($data['target_mission']);

        $distance = $this->fleetsService->targetDistance(
            $this->planetInt('planet_galaxy'),
            $this->asInt($data['galaxy']),
            $this->planetInt('planet_system'),
            $this->asInt($data['system']),
            $this->planetInt('planet_planet'),
            $this->asInt($data['planet'])
        );

        $fleet = $this->sessionShips();
        $speedFactor = Functions::fleetSpeedFactor();
        $fleetSpeed = $this->fleetsService->fleetMaxSpeed($fleet, $this->driveLevel('combustion'), $this->driveLevel('impulse'), $this->driveLevel('hyperspace'));
        $minSpeed = $fleetSpeed === [] ? 0 : (int) min($fleetSpeed);

        $consumption = $this->fleetsService->fleetConsumption(
            $fleet,
            $speedFactor,
            (int) $this->fleetsService->missionDuration($this->asInt($data['speed']), (float) $minSpeed, $distance, $speedFactor),
            $distance,
            $this->driveLevel('combustion'),
            $this->driveLevel('impulse'),
            $this->driveLevel('hyperspace')
        );

        session([
            'fleet_data' => array_merge($this->sessionFleetData(), [
                'speed' => $this->asInt($data['speed']),
                'target' => [
                    'galaxy' => $this->asInt($data['galaxy']),
                    'system' => $this->asInt($data['system']),
                    'planet' => $this->asInt($data['planet']),
                    'type' => $this->asInt($data['planettype']),
                    'group' => $this->asInt($data['fleet_group']),
                    'acs_target' => $this->asString($data['acs_target']),
                ],
                'distance' => $distance,
                'consumption' => $consumption,
            ]),
        ]);

        return [
            'this_metal' => (int) floor((float) $this->planetInt('planet_metal')),
            'this_crystal' => (int) floor((float) $this->planetInt('planet_crystal')),
            'this_deuterium' => (int) floor((float) $this->planetInt('planet_deuterium')),
            'this_galaxy' => $this->planetInt('planet_galaxy'),
            'this_system' => $this->planetInt('planet_system'),
            'this_planet' => $this->planetInt('planet_planet'),
            'this_planet_type' => $this->planetInt('planet_type'),
            'galaxy_end' => $this->asInt($data['galaxy']),
            'system_end' => $this->asInt($data['system']),
            'planet_end' => $this->asInt($data['planet']),
            'planet_type_end' => $this->asInt($data['planettype']),
            'speed' => $this->asInt($data['speed']),
            'speedfactor' => Functions::fleetSpeedFactor(),
        ];
    }

    /**
     * Validate the eight required target fields; bail to step 1 if any is
     * missing/invalid or the target is the current planet.
     *
     * @return array<string, int|string>
     */
    private function validatedTarget(Request $request): array
    {
        $fields = [
            'galaxy' => $this->rangeOrNull($request, 'galaxy', 1, MAX_GALAXY_IN_WORLD),
            'system' => $this->rangeOrNull($request, 'system', 1, MAX_SYSTEM_IN_GALAXY),
            'planet' => $this->rangeOrNull($request, 'planet', 1, MAX_PLANET_IN_SYSTEM + 1),
            'planettype' => $this->rangeOrNull($request, 'planettype', 1, 3),
            'speed' => $this->rangeOrNull($request, 'speed', 1, 10),
            'target_mission' => $this->intOrNull($request, 'target_mission'),
            'fleet_group' => $this->intOrNull($request, 'fleet_group'),
            'acs_target' => $this->rawOrNull($request, 'acs_target'),
        ];

        $kept = array_filter($fields, fn (int|string|null $value): bool => $value !== null && (string) $value !== '');

        if (count($kept) !== 8 || $this->isCurrentPlanet($fields)) {
            $this->redirectToStart();
        }

        /** @var array<string, int|string> $fields */
        return $fields;
    }

    /**
     * @return array<int, int>
     */
    private function sessionShips(): array
    {
        $data = $this->sessionFleetData();

        if (!isset($data['fleetarray']) || !is_string($data['fleetarray'])) {
            $this->redirectToStart();
        }

        $decoded = unserialize(base64_decode(str_rot13($data['fleetarray'])), ['allowed_classes' => false]);

        if (!is_array($decoded)) {
            return [];
        }

        $ships = [];

        foreach ($decoded as $shipId => $amount) {
            if (is_numeric($shipId)) {
                $ships[(int) $shipId] = $this->asInt($amount);
            }
        }

        return $ships;
    }

    /**
     * @param  array<string, mixed>  $targetPlanet
     */
    private function isFriendly(array $targetPlanet): bool
    {
        $targetUserId = $this->asInt($targetPlanet['planet_user_id'] ?? 0);

        $buddyRow = DB::selectOne(
            $this->prepareSql(
                'SELECT COUNT(*) AS buddies
                FROM `' . BUDDY . '`
                WHERE (
                    (`buddy_sender` = ? AND `buddy_receiver` = ?)
                    OR (`buddy_sender` = ? AND `buddy_receiver` = ?)
                ) AND `buddy_status` = 1;'
            ),
            [$this->userInt('id'), $targetUserId, $targetUserId, $this->userInt('id')]
        );

        $isBuddy = (is_object($buddyRow) ? $this->asInt(get_object_vars($buddyRow)['buddies'] ?? 0) : 0) >= 1;

        if ($isBuddy) {
            return true;
        }

        $targetAlly = $this->asInt($targetPlanet['ally_id'] ?? 0);
        $userAlly = $this->userInt('ally_id');

        return !(($targetAlly === 0 && $userAlly === 0) || $targetAlly !== $userAlly);
    }

    /**
     * @param  array<string, int|string|null>  $target
     */
    private function isCurrentPlanet(array $target): bool
    {
        return Functions::isCurrentPlanet(
            $this->planet,
            [
                'planet_galaxy' => $target['galaxy'] ?? 0,
                'planet_system' => $target['system'] ?? 0,
                'planet_planet' => $target['planet'] ?? 0,
                'planet_type' => $target['planettype'] ?? 0,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTargetPlanet(int $galaxy, int $system, int $planet, int $type): array
    {
        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT p.`planet_user_id`, u.`ally_id`
                FROM `' . PLANETS . '` AS p
                INNER JOIN `' . USERS . '` AS u ON u.`id` = p.`planet_user_id`
                WHERE p.`planet_galaxy` = ? AND p.`planet_system` = ? AND p.`planet_planet` = ? AND p.`planet_type` = ?;'
            ),
            [$galaxy, $system, $planet, $type]
        );

        return is_object($row) ? get_object_vars($row) : [];
    }

    private function acsGroupCount(int $acsId): int
    {
        $row = DB::selectOne(
            $this->prepareSql('SELECT COUNT(`acs_id`) AS `acs_amount` FROM `' . ACS . '` WHERE `acs_id` = ?;'),
            [$acsId]
        );

        return is_object($row) ? $this->asInt(get_object_vars($row)['acs_amount'] ?? 0) : 0;
    }

    /**
     * @param  array<int, int>  $missions
     * @return array<int, int>
     */
    private function without(array $missions, int $mission): array
    {
        return array_values(array_filter($missions, fn (int $value): bool => $value !== $mission));
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function shipMissionRules(): array
    {
        $combat = [Missions::ATTACK, Missions::ACS, Missions::TRANSPORT, Missions::DEPLOY, Missions::STAY, Missions::EXPEDITION];

        return [
            Ships::ship_small_cargo_ship => $combat,
            Ships::ship_big_cargo_ship => $combat,
            Ships::ship_light_fighter => $combat,
            Ships::ship_heavy_fighter => $combat,
            Ships::ship_cruiser => $combat,
            Ships::ship_battleship => $combat,
            Ships::ship_colony_ship => [Missions::DEPLOY, Missions::COLONIZE, Missions::EXPEDITION],
            Ships::ship_recycler => [Missions::DEPLOY, Missions::RECYCLE, Missions::EXPEDITION],
            Ships::ship_espionage_probe => [Missions::ATTACK, Missions::ACS, Missions::DEPLOY, Missions::STAY, Missions::SPY, Missions::EXPEDITION],
            Ships::ship_bomber => $combat,
            Ships::ship_solar_satellite => [],
            Ships::ship_destroyer => $combat,
            Ships::ship_deathstar => [Missions::ATTACK, Missions::ACS, Missions::TRANSPORT, Missions::DEPLOY, Missions::STAY, Missions::DESTROY, Missions::EXPEDITION],
            Ships::ship_reaper => $combat,
        ];
    }

    /**
     * @return array<int, array<string, array<int, int>>>
     */
    private function targetMissionRules(): array
    {
        return [
            PlanetTypes::PLANET => [
                'own' => [Missions::TRANSPORT, Missions::DEPLOY],
                'other' => [Missions::ATTACK, Missions::ACS, Missions::TRANSPORT, Missions::STAY, Missions::SPY, Missions::COLONIZE],
            ],
            PlanetTypes::DEBRIS => [
                'own' => [Missions::RECYCLE],
                'other' => [Missions::RECYCLE],
            ],
            PlanetTypes::MOON => [
                'own' => [Missions::TRANSPORT, Missions::DEPLOY],
                'other' => [Missions::ATTACK, Missions::ACS, Missions::TRANSPORT, Missions::STAY, Missions::SPY, Missions::DESTROY],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionFleetData(): array
    {
        $data = session('fleet_data');

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionTarget(): array
    {
        $data = $this->sessionFleetData();

        return isset($data['target']) && is_array($data['target']) ? $data['target'] : [];
    }

    private function rangeOrNull(Request $request, string $key, int $min, int $max): ?int
    {
        if (!$request->has($key) || !is_numeric($request->post($key))) {
            return null;
        }

        $value = $request->integer($key);

        return $value >= $min && $value <= $max ? $value : null;
    }

    private function intOrNull(Request $request, string $key): ?int
    {
        return $request->has($key) && is_numeric($request->post($key)) ? $request->integer($key) : null;
    }

    private function rawOrNull(Request $request, string $key): ?string
    {
        $value = $request->post($key);

        return is_scalar($value) ? (string) $value : null;
    }

    private function planetTypeName(int $type): string
    {
        return $this->translationEntry('game/global.planet_type', $type);
    }

    private function missionName(int $mission): string
    {
        return $this->translationEntry('game/missions.type_mission', $mission);
    }

    private function translationEntry(string $key, int $index): string
    {
        $entries = trans($key);

        if (is_array($entries) && isset($entries[$index]) && is_scalar($entries[$index])) {
            return (string) $entries[$index];
        }

        return '';
    }

    private function redirectToStart(): never
    {
        throw new HttpResponseException(redirect(self::REDIRECT_TARGET));
    }

    private function driveLevel(string $drive): int
    {
        return $this->userInt('research_' . $drive . '_drive');
    }

    private function planetInt(string $key): int
    {
        $value = $this->planet[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function userInt(string $key): int
    {
        $value = $this->user[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
