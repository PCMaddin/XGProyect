<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Objects;
use Xgp\App\Libraries\FleetsLib;
use App\Services\Game\Formulas\FormulasService;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\GalaxyLib;
use App\Libraries\NoobsProtectionLib;
use Xgp\App\Libraries\Users;

/**
 * Galaxy view together with the fleet and missile dispatch actions triggered
 * from a system row.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class GalaxyController extends BaseController
{
    use PreparesLegacySql;

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    /** @var array<int, array<string, mixed>> */
    private array $galaxy = [];

    private int $planetCount = 0;

    private int $currentGalaxy = 0;

    private int $currentSystem = 0;

    private Objects $objects;

    private NoobsProtectionLib $noob;

    public function __construct(
        private FormatService $formatService,
        private FleetsService $fleetsService,
        private OfficerService $officerService,
        private FormulasService $formulasService,
        private SettingsService $settingsService,
    ) {
    }

    public function __invoke(Request $request): View|Response
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Galaxy));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();
        $this->objects = new Objects();
        $this->noob = new NoobsProtectionLib();

        if ($this->userInt('preference_vacation_mode') > 0) {
            Functions::message((string) __('game/galaxy.gl_no_access_vm_on'), '', 0);
        }

        $response = $this->runAction($request);

        if ($response !== null) {
            return $response;
        }

        return $this->buildPage($request);
    }

    private function runAction(Request $request): ?Response
    {
        if ($request->query('fleet') === 'true') {
            return $this->sendFleet($request);
        }

        if ($request->query('missiles') === 'true') {
            $this->sendMissiles($request);
        }

        return null;
    }

    private function buildPage(Request $request): View
    {
        $mode = $request->integer('mode');
        $position = $this->validatePosition($request, $mode);
        $this->currentGalaxy = $position['galaxy'];
        $this->currentSystem = $position['system'];

        if ($mode === 2 && $this->planetInt('defense_interplanetary_missile') < 1) {
            Functions::message((string) __('game/galaxy.gl_no_missiles'), 'game.php?page=galaxy&mode=0', 2);
        }

        $this->galaxy = $this->loadSystem();

        $parse = [
            'selected_galaxy' => $this->currentGalaxy,
            'selected_system' => $this->currentSystem,
            'selected_planet' => $position['planet'],
            'currentmip' => $this->planetInt('defense_interplanetary_missile'),
            'maxfleetcount' => $this->currentFleets(),
            'fleetmax' => $this->fleetsService->getMaxFleets(
                $this->userInt('research_computer_technology'),
                $this->officerService->isOfficerActive($this->userInt('premium_officier_admiral'), time())
            ),
            'recyclers' => $this->formatService->prettyNumber($this->planetInt('ship_recycler')),
            'spyprobes' => $this->formatService->prettyNumber($this->planetInt('ship_espionage_probe')),
            'missile_count' => sprintf((string) __('game/galaxy.gl_missil_to_launch'), $this->planetInt('defense_interplanetary_missile')),
            'current' => $request->has('current') ? $request->integer('current') : null,
            'current_galaxy' => $this->planetInt('planet_galaxy'),
            'current_system' => $this->planetInt('planet_system'),
            'current_planet' => $this->planetInt('planet_planet'),
            'coords' => $this->formatService->prettyCoords($this->currentGalaxy, $this->currentSystem, $position['planet']),
            'planet_type' => $this->planetInt('planet_type'),
        ];

        $parse['mip'] = $mode === 2 ? view('galaxy.galaxy_missile_selector', $parse)->render() : ' ';

        return view('galaxy.galaxy_view', array_merge(
            [
                'list_of_positions' => $this->buildPositionsList(),
                'planet_count' => $this->planetCount,
                'max_galaxy' => MAX_GALAXY_IN_WORLD,
                'max_system' => MAX_SYSTEM_IN_GALAXY,
            ],
            $parse
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSystem(): array
    {
        return array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql(
                    'SELECT
                        (SELECT CONCAT(GROUP_CONCAT(`buddy_receiver`), \',\', GROUP_CONCAT(`buddy_sender`))
                            FROM `' . BUDDY . '` AS b
                            WHERE b.`buddy_receiver` = ? OR b.`buddy_sender` = ?) AS `buddys`,
                        p.`planet_debris_metal` AS `metal`, p.`planet_debris_crystal` AS `crystal`,
                        p.`planet_id` AS `id_planet`, p.`planet_galaxy`, p.`planet_system`, p.`planet_planet`,
                        p.`planet_type`, p.`planet_destroyed`, p.`planet_name`, p.`planet_image`,
                        p.`planet_last_update`, p.`planet_user_id`,
                        u.`id`, u.`ally_id`, b.`user_id` AS `banned`, pr.`preference_vacation_mode`,
                        u.`onlinetime`, u.`name`, u.`authlevel`,
                        s.`user_statistic_total_rank`, s.`user_statistic_total_points`,
                        m.`planet_id` AS `id_luna`, m.`planet_diameter`, m.`planet_temp_min`,
                        m.`planet_destroyed` AS `destroyed_moon`, m.`planet_name` AS `name_moon`,
                        a.`alliance_name`, a.`alliance_tag`, a.`alliance_web`,
                        (SELECT COUNT(`id`) FROM `' . USERS . '` WHERE `ally_id` = a.`alliance_id`) AS `ally_members`
                    FROM `' . PLANETS . '` AS p
                        INNER JOIN `' . USERS . '` AS u ON p.`planet_user_id` = u.`id`
                        INNER JOIN `' . PREFERENCES . '` AS pr ON pr.`preference_user_id` = u.`id`
                        INNER JOIN `' . USERS_STATISTICS . '` AS s ON s.`user_statistic_user_id` = u.`id`
                        LEFT JOIN `' . ALLIANCE . '` AS a ON a.`alliance_id` = u.`ally_id`
                        LEFT JOIN `' . PLANETS . '` AS m ON m.`planet_id` = (
                            SELECT mp.`planet_id` FROM `' . PLANETS . '` AS mp
                            WHERE mp.`planet_galaxy` = p.`planet_galaxy` AND mp.`planet_system` = p.`planet_system`
                                AND mp.`planet_planet` = p.`planet_planet` AND mp.`planet_type` = 3)
                        LEFT JOIN `' . BANNED . '` AS b ON b.`user_id` = u.`id`
                    WHERE p.`planet_galaxy` = ? AND p.`planet_system` = ? AND p.`planet_type` = 1
                        AND p.`planet_planet` > 0 AND p.`planet_planet` <= ?
                    ORDER BY p.`planet_planet`;'
                ),
                [$this->userInt('id'), $this->userInt('id'), $this->currentGalaxy, $this->currentSystem, MAX_PLANET_IN_SYSTEM]
            )
        );
    }

    private function currentFleets(): int
    {
        $row = DB::selectOne(
            $this->prepareSql('SELECT COUNT(`fleet_id`) AS total_fleets FROM `' . FLEETS . '` WHERE `fleet_owner` = ?;'),
            [$this->userInt('id')]
        );

        return is_object($row) ? $this->asInt(get_object_vars($row)['total_fleets'] ?? 0) : 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPositionsList(): array
    {
        $positions = [];
        $galaxyRow = new GalaxyLib($this->user, $this->planet, $this->currentGalaxy, $this->currentSystem);

        foreach ($this->galaxy as $planet) {
            $this->planetCount++;
            $slot = $this->asInt($planet['planet_planet'] ?? 0);
            $positions[$slot] = $galaxyRow->buildRow($planet, $slot);
        }

        for ($slot = 1; $slot <= MAX_PLANET_IN_SYSTEM; $slot++) {
            $positions[$slot] ??= [
                'pos' => $slot,
                'planet' => '',
                'planetname' => '',
                'moon' => '',
                'debris' => '',
                'username' => '',
                'alliance' => '',
                'actions' => '',
            ];
        }

        ksort($positions);

        return $positions;
    }

    /**
     * @return array{galaxy: int, system: int, planet: int}
     */
    private function validatePosition(Request $request, int $mode): array
    {
        return match ($mode) {
            1 => $this->navigatePosition($request),
            2 => [
                'galaxy' => $request->integer('galaxy'),
                'system' => $request->integer('system'),
                'planet' => $request->integer('planet'),
            ],
            3 => [
                'galaxy' => $request->integer('galaxy'),
                'system' => $request->integer('system'),
                'planet' => 0,
            ],
            0 => [
                'galaxy' => $this->planetInt('planet_galaxy'),
                'system' => $this->planetInt('planet_system'),
                'planet' => $this->planetInt('planet_planet'),
            ],
            default => ['galaxy' => 1, 'system' => 1, 'planet' => 0],
        };
    }

    /**
     * @return array{galaxy: int, system: int, planet: int}
     */
    private function navigatePosition(Request $request): array
    {
        $galaxy = max($request->integer('galaxy'), 1);
        $system = max($request->integer('system'), 1);

        if ($request->has('galaxyRight')) {
            $galaxy = $galaxy >= MAX_GALAXY_IN_WORLD ? 1 : $galaxy + 1;
        }

        if ($request->has('galaxyLeft')) {
            $galaxy = $galaxy <= 1 ? MAX_GALAXY_IN_WORLD : $galaxy - 1;
        }

        if ($request->has('systemRight')) {
            $system = $system >= MAX_SYSTEM_IN_GALAXY ? 1 : $system + 1;
        }

        if ($request->has('systemLeft')) {
            $system = $system <= 1 ? MAX_SYSTEM_IN_GALAXY : $system - 1;
        }

        return ['galaxy' => $galaxy, 'system' => $system, 'planet' => 0];
    }

    /**
     * Launch interplanetary missiles at a target. Ends by throwing a LegacyView
     * (via Functions::message) in every branch, so it does not return a value.
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    private function sendMissiles(Request $request): void
    {
        $galaxy = $this->queryInt($request, 'galaxy');
        $system = $this->queryInt($request, 'system');
        $planet = $this->queryInt($request, 'planet');
        $missilesAmount = max($this->postInt($request, 'SendMI'), 0);
        $rawTarget = $request->post('Target');
        $target = is_scalar($rawTarget) ? (string) $rawTarget : '';

        $currentMissiles = $this->planetInt('defense_interplanetary_missile');
        $distance = abs($system - $this->planetInt('planet_system'));
        $range = $this->formulasService->missileRange($this->userInt('research_impulse_drive'));

        $targetUser = $this->fetchTargetUser($galaxy, $system, $planet, 1);

        $error = '';

        if ($this->planetInt('building_missile_silo') < 4) {
            $error .= __('game/galaxy.gl_silo_level') . '<br>';
        }

        if ($this->userInt('research_impulse_drive') === 0) {
            $error .= __('game/galaxy.gl_impulse_drive_required') . '<br>';
        }

        if ($distance >= $range || $galaxy !== $this->planetInt('planet_galaxy')) {
            $error .= __('game/galaxy.gl_not_send_other_galaxy') . '<br>';
        }

        if ($targetUser === null) {
            $error .= __('game/galaxy.gl_planet_doesnt_exists') . '<br>';
        }

        if ($missilesAmount > $currentMissiles) {
            $error .= __('game/galaxy.gl_cant_send') . $missilesAmount . __('game/galaxy.gl_missile') . $currentMissiles . '<br>';
        }

        if (!$this->isValidMissileTarget($target)) {
            $error .= __('game/galaxy.gl_wrong_target') . '<br>';
        }

        if ($currentMissiles === 0) {
            $error .= __('game/galaxy.gl_no_missiles') . '<br>';
        }

        if ($missilesAmount === 0) {
            $error .= __('game/galaxy.gl_add_missile_number') . '<br>';
        }

        if ($targetUser !== null) {
            $error .= $this->missileProtectionError($targetUser);
        }

        if ($error !== '') {
            Functions::message($error, 'game.php?page=galaxy&mode=0&galaxy=' . $galaxy . '&system=' . $system, 3);
        }

        /** @var array<string, mixed> $targetUser */
        $this->insertMissileFleet($missilesAmount, $distance, $galaxy, $system, $planet, $target, $this->asInt($targetUser['id'] ?? 0));

        Functions::message(
            '<b>' . $missilesAmount . '</b>' . __('game/galaxy.gl_missiles_sended') . $this->defenseLabel($target),
            'game.php?page=overview',
            3
        );
    }

    /**
     * Noob-protection and vacation checks against the missile target.
     *
     * @param  array<string, mixed>  $targetUser
     */
    private function missileProtectionError(array $targetUser): string
    {
        $error = '';
        $points = $this->noob->returnPoints($this->userInt('id'), $this->asInt($targetUser['id'] ?? 0));
        $myLevel = $this->asInt($points['user_points'] ?? 0);
        $hisLevel = $this->asInt($points['target_points'] ?? 0);

        if ($this->asInt($targetUser['onlinetime'] ?? 0) >= (time() - 60 * 60 * 24 * 7)) {
            if ($this->noob->isWeak($myLevel, $hisLevel)) {
                $error .= __('game/fleet.fl_week_player') . '<br>';
            } elseif ($this->noob->isStrong($myLevel, $hisLevel)) {
                $error .= __('game/fleet.fl_strong_player') . '<br>';
            }
        }

        if ($this->asInt($targetUser['preference_vacation_mode'] ?? 0) > 0) {
            $error .= __('game/fleet.fl_in_vacation_player') . '<br>';
        }

        return $error;
    }

    private function insertMissileFleet(
        int $amount,
        int $distance,
        int $galaxy,
        int $system,
        int $planet,
        string $target,
        int $targetOwner
    ): void {
        $flightTime = (int) round(((30 + (60 * $distance)) * 2500) / max($this->settingsService->getInt('fleet_speed'), 1));
        $now = time();

        DB::transaction(function () use ($amount, $flightTime, $galaxy, $system, $planet, $target, $targetOwner, $now): void {
            DB::insert(
                $this->prepareSql(
                    'INSERT INTO `' . FLEETS . '` SET
                        `fleet_owner` = ?, `fleet_mission` = 10, `fleet_amount` = ?, `fleet_array` = ?,
                        `fleet_start_time` = ?, `fleet_start_galaxy` = ?, `fleet_start_system` = ?,
                        `fleet_start_planet` = ?, `fleet_start_type` = 1, `fleet_end_time` = ?,
                        `fleet_end_stay` = 0, `fleet_end_galaxy` = ?, `fleet_end_system` = ?,
                        `fleet_end_planet` = ?, `fleet_end_type` = 1, `fleet_target_obj` = ?,
                        `fleet_resource_metal` = 0, `fleet_resource_crystal` = 0, `fleet_resource_deuterium` = 0,
                        `fleet_target_owner` = ?, `fleet_group` = 0, `fleet_mess` = 0, `fleet_creation` = ?;'
                ),
                [
                    $this->userInt('id'),
                    $amount,
                    FleetsLib::setFleetShipsArray([503 => $amount]),
                    $now + $flightTime,
                    $this->planetInt('planet_galaxy'),
                    $this->planetInt('planet_system'),
                    $this->planetInt('planet_planet'),
                    $now + $flightTime + 1,
                    $galaxy,
                    $system,
                    $planet,
                    $target,
                    $targetOwner,
                    $now,
                ]
            );

            DB::update(
                $this->prepareSql(
                    'UPDATE `' . DEFENSES . '` SET
                        `defense_interplanetary_missile` = `defense_interplanetary_missile` - ?
                    WHERE `defense_planet_id` = ?;'
                ),
                [$amount, $this->userInt('current_planet')]
            );
        });
    }

    private function isValidMissileTarget(string $target): bool
    {
        if ($target === 'all') {
            return true;
        }

        return is_numeric($target) && (int) $target >= 0 && (int) $target <= 8;
    }

    private function defenseLabel(string $target): string
    {
        $labels = [
            0 => (string) __('game/galaxy.gl_all_defenses'),
            1 => (string) __('game/defenses.defense_rocket_launcher'),
            2 => (string) __('game/defenses.defense_light_laser'),
            3 => (string) __('game/defenses.defense_heavy_laser'),
            4 => (string) __('game/defenses.defense_gauss_cannon'),
            5 => (string) __('game/defenses.defense_ion_cannon'),
            6 => (string) __('game/defenses.defense_plasma_turret'),
            7 => (string) __('game/defenses.defense_small_shield_dome'),
            8 => (string) __('game/defenses.defense_large_shield_dome'),
        ];

        return $labels[is_numeric($target) ? (int) $target : 0] ?? '';
    }

    /**
     * Dispatch a fleet from a galaxy row. Returns the numeric status code the
     * frontend JavaScript expects as the response body.
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     */
    private function sendFleet(Request $request): Response
    {
        $order = $this->postInt($request, 'order');
        $fleetArray = $this->collectFleetShips($request, $order);

        if ($fleetArray['partial'] && $fleetArray['count'] < 1) {
            return new Response('611 ');
        }

        $ships = $fleetArray['ships'];
        $galaxy = $this->postInt($request, 'galaxy');
        $system = $this->postInt($request, 'system');
        $planet = $this->postInt($request, 'planet');
        $planetType = $request->post('planettype') !== null ? $this->postInt($request, 'planettype') : GalaxyLib::PLANET_TYPE;

        if (
            ($galaxy > MAX_GALAXY_IN_WORLD || $galaxy < 1)
            || ($system > MAX_SYSTEM_IN_GALAXY || $system < 1)
            || ($planet > MAX_PLANET_IN_SYSTEM || $planet < 1)
            || $ships === []
        ) {
            return new Response('614 ');
        }

        $currentFleets = $this->currentFleets();
        $targetUser = $this->fetchTargetUser($galaxy, $system, $planet, $this->targetLookupPlanetType($planetType)) ?? $this->user;

        if ($order === 8) {
            $blocked = $this->invisibleDebrisEmpty($galaxy, $system, $planet);

            if ($blocked !== null) {
                return $blocked;
            }
        }

        $points = $this->noob->returnPoints($this->userInt('id'), $this->asInt($targetUser['id'] ?? 0));
        $currentPoints = $this->asInt($points['user_points'] ?? 0);
        $targetPoints = $this->asInt($points['target_points'] ?? 0);
        $targetId = $this->asInt($targetUser['id'] ?? 0);

        $maxFleets = $this->fleetsService->getMaxFleets(
            $this->userInt('research_computer_technology'),
            $this->officerService->isOfficerActive($this->userInt('premium_officier_admiral'), time())
        );

        if ($maxFleets <= $currentFleets) {
            return new Response('612 ');
        }

        if ($order !== 6 && $order !== 8) {
            return new Response('601 ');
        }

        if (($this->asInt($targetUser['preference_vacation_mode'] ?? 0) > 0 && $order !== 8) || $this->userInt('preference_vacation_mode') > 0) {
            return new Response('605 ');
        }

        if ($this->asInt($targetUser['onlinetime'] ?? 0) >= (time() - 60 * 60 * 24 * 7)) {
            if ($this->noob->isWeak($currentPoints, $targetPoints) && $targetId !== 0 && $order === 6) {
                return new Response('603 ');
            }

            if ($this->noob->isStrong($currentPoints, $targetPoints) && $targetId !== 0 && $order === 6) {
                return new Response('604 ');
            }
        }

        if ($targetId === 0 && $order !== 8) {
            return new Response('601 ');
        }

        if ($targetId === $this->planetInt('planet_user_id') && $order === 6) {
            return new Response('601 ');
        }

        $consumption = $this->fleetConsumption($ships, $galaxy, $system, $planet);

        if ($this->planetInt('planet_deuterium') < $consumption['fuel']) {
            return new Response('613 ');
        }

        if ($this->settingsService->getInt('adm_attack') === 1 && $this->asInt($targetUser['authlevel'] ?? 0) > 0) {
            return new Response('601 ');
        }

        $this->persistFleet($ships, $order, $planetType, $galaxy, $system, $planet, $targetId, $consumption);

        return new Response($this->fleetResultMessage($ships, $consumption['shipCount']));
    }

    /**
     * Read the requested ship amounts, clamping each to what the planet holds.
     *
     * @return array{ships: array<int, int>, count: int, partial: bool}
     */
    private function collectFleetShips(Request $request, int $order): array
    {
        $inputs = [];

        foreach ($this->fleetShipIds() as $shipId) {
            $inputs[$shipId] = $this->postInt($request, 'ship' . $shipId);
        }

        $shipCount = $this->postInt($request, 'shipcount');

        match ($order) {
            6 => $inputs[210] = $shipCount,
            7 => $inputs[208] = $shipCount,
            8 => $inputs[209] = $shipCount,
            default => null,
        };

        $ships = [];
        $partialCount = 0;
        $partial = false;

        foreach ($inputs as $shipId => $amount) {
            if ($shipId <= 200 || $shipId >= 300 || $amount <= 0) {
                continue;
            }

            $available = $this->planetInt($this->resourceName($shipId));
            $ships[$shipId] = min($amount, $available);

            if ($amount > $available) {
                $partialCount += $available;
                $partial = true;
            }
        }

        return ['ships' => $ships, 'count' => $partialCount, 'partial' => $partial];
    }

    /**
     * Debris fields sent to (order 8) are refused when both piles are empty and
     * the invisibility window has elapsed. Returns the response to send, or null
     * when the fleet may proceed.
     */
    private function invisibleDebrisEmpty(int $galaxy, int $system, int $planet): ?Response
    {
        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT `planet_invisible_start_time`, `planet_debris_metal`, `planet_debris_crystal`
                FROM `' . PLANETS . '`
                WHERE `planet_galaxy` = ? AND `planet_system` = ? AND `planet_planet` = ? AND `planet_type` = 1;'
            ),
            [$galaxy, $system, $planet]
        );

        if (!is_object($row)) {
            return new Response('');
        }

        $debris = get_object_vars($row);

        if (
            $this->asInt($debris['planet_debris_metal'] ?? 0) === 0
            && $this->asInt($debris['planet_debris_crystal'] ?? 0) === 0
            && time() > ($this->asInt($debris['planet_invisible_start_time'] ?? 0) + DEBRIS_LIFE_TIME)
        ) {
            return new Response('');
        }

        return null;
    }

    /**
     * Distance, duration and fuel for the fleet.
     *
     * @param  array<int, int>  $ships
     * @return array{fuel: int, duration: int, startTime: int, endTime: int, shipCount: int}
     */
    private function fleetConsumption(array $ships, int $galaxy, int $system, int $planet): array
    {
        $distance = $this->fleetsService->targetDistance(
            $this->planetInt('planet_galaxy'),
            $galaxy,
            $this->planetInt('planet_system'),
            $system,
            $this->planetInt('planet_planet'),
            $planet
        );
        $speeds = $this->fleetsService->fleetMaxSpeed(
            $ships,
            $this->userInt('research_combustion_drive'),
            $this->userInt('research_impulse_drive'),
            $this->userInt('research_hyperspace_drive')
        );
        $minSpeed = $speeds === [] ? 0.0 : (float) min($speeds);
        $duration = (int) $this->fleetsService->missionDuration(10, $minSpeed, $distance, Functions::fleetSpeedFactor());
        $speedFactor = Functions::fleetSpeedFactor();

        $consumption = 0.0;
        $shipCount = 0;

        foreach ($ships as $ship => $count) {
            $shipSpeed = max($this->shipSpeed($ship), 1);
            $spd = 35000 / (($duration * $speedFactor) - 10) * sqrt($distance * 10 / $shipSpeed);
            $basicConsumption = $this->shipConsumption($ship) * $count;
            $consumption += $basicConsumption * $distance / 35000 * (($spd / 10) + 1) * (($spd / 10) + 1);
            $shipCount += $count;
        }

        $now = time();

        return [
            'fuel' => (int) round($consumption) + 1,
            'duration' => $duration,
            'startTime' => $duration + $now,
            'endTime' => ($duration * 2) + $now,
            'shipCount' => $shipCount,
        ];
    }

    /**
     * @param  array<int, int>  $ships
     * @param  array{fuel: int, duration: int, startTime: int, endTime: int, shipCount: int}  $consumption
     */
    private function persistFleet(
        array $ships,
        int $order,
        int $planetType,
        int $galaxy,
        int $system,
        int $planet,
        int $targetOwner,
        array $consumption
    ): void {
        $now = time();

        DB::transaction(function () use ($ships, $order, $planetType, $galaxy, $system, $planet, $targetOwner, $consumption, $now): void {
            DB::insert(
                $this->prepareSql(
                    'INSERT INTO `' . FLEETS . '` SET
                        `fleet_owner` = ?, `fleet_mission` = ?, `fleet_amount` = ?, `fleet_array` = ?,
                        `fleet_start_time` = ?, `fleet_start_galaxy` = ?, `fleet_start_system` = ?,
                        `fleet_start_planet` = ?, `fleet_start_type` = ?, `fleet_end_time` = ?,
                        `fleet_end_galaxy` = ?, `fleet_end_system` = ?, `fleet_end_planet` = ?,
                        `fleet_end_type` = ?, `fleet_resource_metal` = 0, `fleet_resource_crystal` = 0,
                        `fleet_resource_deuterium` = 0, `fleet_fuel` = ?, `fleet_target_owner` = ?,
                        `fleet_creation` = ?;'
                ),
                [
                    $this->userInt('id'),
                    $order,
                    $consumption['shipCount'],
                    FleetsLib::setFleetShipsArray($ships),
                    $consumption['startTime'],
                    $this->planetInt('planet_galaxy'),
                    $this->planetInt('planet_system'),
                    $this->planetInt('planet_planet'),
                    $this->planetInt('planet_type'),
                    $consumption['endTime'],
                    $galaxy,
                    $system,
                    $planet,
                    $planetType,
                    $consumption['fuel'],
                    $targetOwner,
                    $now,
                ]
            );

            $assignments = [];
            $bindings = [];

            foreach ($ships as $ship => $count) {
                $column = $this->resourceName($ship);

                if ($column === '') {
                    continue;
                }

                $assignments[] = '`' . $column . '` = `' . $column . '` - ?';
                $bindings[] = $count;
            }

            $bindings[] = $consumption['fuel'];
            $bindings[] = $this->planetInt('planet_id');

            DB::update(
                $this->prepareSql(
                    'UPDATE `' . PLANETS . '` AS p
                    INNER JOIN `' . SHIPS . '` AS s ON s.`ship_planet_id` = p.`planet_id` SET
                    ' . implode(', ', $assignments) . ',
                    `planet_deuterium` = `planet_deuterium` - ?
                    WHERE `planet_id` = ?;'
                ),
                $bindings
            );
        });
    }

    /**
     * @param  array<int, int>  $ships
     */
    private function fleetResultMessage(array $ships, int $shipCount): string
    {
        $maxSpyProbes = $this->userInt('preference_spy_probes');

        foreach (array_keys($ships) as $ship) {
            if ($maxSpyProbes > $this->planetInt($this->resourceName($ship))) {
                return '610 ' . $shipCount;
            }
        }

        return '600 ' . (array_key_last($ships) ?? '');
    }

    private function targetLookupPlanetType(int $planetType): int
    {
        return $planetType === GalaxyLib::DEBRIS_TYPE ? GalaxyLib::PLANET_TYPE : $planetType;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTargetUser(int $galaxy, int $system, int $planet, int $planetType): ?array
    {
        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT u.`id`, u.`onlinetime`, u.`authlevel`, pr.`preference_vacation_mode`
                FROM `' . USERS . '` AS u
                INNER JOIN `' . PREFERENCES . '` AS pr ON pr.`preference_user_id` = u.`id`
                WHERE u.`id` = (
                    SELECT `planet_user_id` FROM `' . PLANETS . '`
                    WHERE `planet_galaxy` = ? AND `planet_system` = ? AND `planet_planet` = ? AND `planet_type` = ?
                    LIMIT 1
                )
                LIMIT 1;'
            ),
            [$galaxy, $system, $planet, $planetType]
        );

        return is_object($row) ? get_object_vars($row) : null;
    }

    /**
     * @return array<int, int>
     */
    private function fleetShipIds(): array
    {
        $list = $this->objects->getObjectsList('fleet');

        if (!is_array($list)) {
            return [];
        }

        $ids = [];

        foreach ($list as $shipId) {
            if (is_numeric($shipId)) {
                $ids[] = (int) $shipId;
            }
        }

        return $ids;
    }

    private function resourceName(int $itemId): string
    {
        $name = $this->objects->getObjects($itemId);

        return is_string($name) ? $name : '';
    }

    private function shipSpeed(int $itemId): int
    {
        return $this->asInt($this->objects->getPrice($itemId, 'speed'));
    }

    private function shipConsumption(int $itemId): int
    {
        return $this->asInt($this->objects->getPrice($itemId, 'consumption'));
    }

    private function queryInt(Request $request, string $key): int
    {
        $value = $request->query($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function postInt(Request $request, string $key): int
    {
        $value = $request->post($key);

        return is_numeric($value) ? (int) $value : 0;
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
}
