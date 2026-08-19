<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\SettingsService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator as PlanetTypes;
use Xgp\App\Core\Enumerators\ShipsEnumerator as Ships;
use Xgp\App\Core\Objects;
use Xgp\App\Libraries\FleetsLib;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\Game\Fleets;
use App\Libraries\NoobsProtectionLib;
use App\Libraries\Premium\Premium;
use App\Libraries\Research\Researches;
use Xgp\App\Libraries\Users;

/**
 * Fleet wizard step 4: the commit step. Validates the whole request built up by
 * steps 1-3, and on success writes the fleet row and deducts ships/resources.
 * Errors show a message page; anything else redirects back to the fleet list.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 */
class Fleet4Controller extends BaseController
{
    use PreparesLegacySql;

    public const REDIRECT_TARGET = 'game.php?page=movement';

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    private Fleets $fleets;

    private Researches $research;

    private Premium $premium;

    private Users $userLibrary;

    private Objects $objects;

    /** @var array<string, int> */
    private array $cleanInput = [];

    /** @var array<string, int|string> */
    private array $fleetData = [
        'fleet_owner' => 0,
        'fleet_mission' => 0,
        'fleet_amount' => 0,
        'fleet_array' => '',
        'fleet_start_time' => 0,
        'fleet_start_galaxy' => 0,
        'fleet_start_system' => 0,
        'fleet_start_planet' => 0,
        'fleet_start_type' => 0,
        'fleet_end_time' => 0,
        'fleet_end_stay' => 0,
        'fleet_end_galaxy' => 0,
        'fleet_end_system' => 0,
        'fleet_end_planet' => 0,
        'fleet_end_type' => 0,
        'fleet_resource_metal' => 0,
        'fleet_resource_crystal' => 0,
        'fleet_resource_deuterium' => 0,
        'fleet_fuel' => 0,
        'fleet_target_owner' => 0,
        'fleet_group' => 0,
    ];

    /** @var array<string, mixed> */
    private array $targetData = [];

    private bool $ownPlanet = false;

    private bool $occupiedPlanet = false;

    private int $fleetStorage = 0;

    /** @var array<string, int> */
    private array $fleetShips = [];

    public function __construct(
        private FormatService $formatService,
        private FleetsService $fleetsService,
        private OfficerService $officerService,
        private SettingsService $settingsService,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Fleet));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();
        $this->userLibrary = new Users();
        $this->objects = new Objects();

        $userId = $this->userInt('id');
        $rows = $userId > 0 ? array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql('SELECT f.* FROM `' . FLEETS . '` f WHERE f.`fleet_owner` = ?;'),
                [$userId]
            )
        ) : [];

        $this->fleets = new Fleets($rows, $userId);
        $this->research = new Researches([$this->user]);
        $this->premium = new Premium([$this->user]);

        return $this->buildPage($request);
    }

    private function buildPage(Request $request): RedirectResponse
    {
        $this->setInputsData($request);
        $this->getTarget();

        if ($this->runValidations()) {
            $this->sendFleet();
        }

        return redirect(self::REDIRECT_TARGET);
    }

    private function setInputsData(Request $request): void
    {
        if ($request->request->count() === 0) {
            $this->redirectTo('game.php?page=fleet1');
        }

        $expTime = $this->research->getCurrentResearch()->getResearchAstrophysics();

        $this->cleanInput = [
            'mission' => $this->rangeInt($request, 'mission', 1, 15),
            'resource1' => $this->rangeInt($request, 'resource1', 0, $this->planetInt('planet_metal')),
            'resource2' => $this->rangeInt($request, 'resource2', 0, $this->planetInt('planet_crystal')),
            'resource3' => $this->rangeInt($request, 'resource3', 0, $this->planetInt('planet_deuterium')),
            'expeditiontime' => $this->rangeInt($request, 'expeditiontime', $expTime <= 0 ? 0 : 1, $expTime),
            'holdingtime' => $this->rangeInt($request, 'holdingtime', 0, 32),
        ];
    }

    private function getTarget(): void
    {
        $target = $this->sessionTarget();
        $galaxy = $this->asInt($target['galaxy'] ?? 0);
        $system = $this->asInt($target['system'] ?? 0);
        $planet = $this->asInt($target['planet'] ?? 0);
        $type = $this->asInt($target['type'] ?? 0);
        $targetType = $type !== 2 ? $type : 1;

        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT p.`planet_user_id`, p.`planet_debris_metal`, p.`planet_debris_crystal`,
                    p.`planet_invisible_start_time`, p.`planet_destroyed`,
                    u.`id`, u.`authlevel`, u.`onlinetime`, u.`ally_id`, pr.`preference_vacation_mode`
                FROM `' . PLANETS . '` p
                INNER JOIN `' . USERS . '` u ON u.`id` = p.`planet_user_id`
                INNER JOIN `' . PREFERENCES . '` pr ON pr.`preference_user_id` = u.`id`
                WHERE p.`planet_galaxy` = ? AND p.`planet_system` = ? AND p.`planet_planet` = ? AND p.`planet_type` = ?;'
            ),
            [$galaxy, $system, $planet, $targetType]
        );

        if (is_object($row)) {
            $this->targetData = get_object_vars($row);
            $this->occupiedPlanet = true;
            $this->ownPlanet = $this->asInt($this->targetData['planet_user_id'] ?? 0) === $this->userInt('id');

            if ($this->asInt($this->targetData['planet_destroyed'] ?? 0) !== 0) {
                $this->redirectTo(self::REDIRECT_TARGET);
            }

            $this->fleetData['fleet_target_owner'] = $this->asInt($this->targetData['planet_user_id'] ?? 0);
        }

        $this->fleetData['fleet_start_galaxy'] = $this->planetInt('planet_galaxy');
        $this->fleetData['fleet_start_system'] = $this->planetInt('planet_system');
        $this->fleetData['fleet_start_planet'] = $this->planetInt('planet_planet');
        $this->fleetData['fleet_start_type'] = $this->planetInt('planet_type');
        $this->fleetData['fleet_end_galaxy'] = $galaxy;
        $this->fleetData['fleet_end_system'] = $system;
        $this->fleetData['fleet_end_planet'] = $planet;
        $this->fleetData['fleet_end_type'] = $type;
    }

    private function runValidations(): bool
    {
        return $this->validateAdmin()
            && $this->validateOwnVacations()
            && $this->validateTargetVacations()
            && $this->validateAcs()
            && $this->validateShips()
            && $this->validateMission()
            && $this->validateNoobProtection()
            && $this->validateFleets()
            && $this->validateResources()
            && $this->validateTime();
    }

    private function validateAdmin(): bool
    {
        if ($this->ownPlanet || !$this->occupiedPlanet) {
            return true;
        }

        if (
            $this->settingsService->getInt('adm_attack') !== 0
            && $this->asInt($this->targetData['authlevel'] ?? 0) >= 1
            && $this->userInt('authlevel') === 0
        ) {
            $this->showMessage((string) __('game/fleet.fl_admins_cannot_be_attacked'));
        }

        return true;
    }

    private function validateOwnVacations(): bool
    {
        if ($this->userLibrary->isOnVacations($this->user)) {
            $this->showMessage((string) __('game/fleet.fl_vacation_mode_active'));
        }

        $this->fleetData['fleet_owner'] = $this->userInt('id');

        return true;
    }

    private function validateTargetVacations(): bool
    {
        if ($this->ownPlanet || !$this->occupiedPlanet) {
            return true;
        }

        if ($this->userLibrary->isOnVacations($this->targetData) && $this->cleanInput['mission'] !== Missions::RECYCLE) {
            $this->showMessage((string) __('game/fleet.fl_in_vacation_player'));
        }

        return true;
    }

    private function validateAcs(): bool
    {
        $target = $this->sessionTarget();
        $group = $this->asInt($target['group'] ?? 0);

        if ($group <= 0 || $this->cleanInput['mission'] !== Missions::ACS) {
            return true;
        }

        $targetString = 'g' . $this->asInt($target['galaxy'] ?? 0)
            . 's' . $this->asInt($target['system'] ?? 0)
            . 'p' . $this->asInt($target['planet'] ?? 0)
            . 't' . $this->asInt($target['type'] ?? 0);

        if ($this->asString($target['acs_target'] ?? '') === $targetString && $this->acsGroupCount($group) > 0) {
            $this->fleetData['fleet_group'] = $group;

            return true;
        }

        return false;
    }

    private function validateShips(): bool
    {
        $fleet = $this->sessionShips();

        if ($fleet === []) {
            return false;
        }

        $planetShips = $this->loadPlanetShips();
        $objects = $this->objects->getObjects();
        $totalShips = 0;

        foreach ($fleet as $shipId => $amount) {
            $column = is_array($objects) && isset($objects[$shipId]) && is_scalar($objects[$shipId]) ? (string) $objects[$shipId] : '';

            if ($column === '' || !isset($planetShips[$column]) || $amount > $this->asInt($planetShips[$column])) {
                return false;
            }

            $totalShips += $amount;
            $this->fleetStorage += $this->fleetsService->getMaxStorage(
                $this->asInt($this->objects->getPrice($shipId, 'capacity')),
                $this->research->getCurrentResearch()->getResearchHyperspaceTechnology()
            ) * $amount;
            $this->fleetShips[$column] = $amount;
        }

        $this->fleetData['fleet_amount'] = $totalShips;
        $this->fleetData['fleet_array'] = FleetsLib::setFleetShipsArray($fleet);

        return true;
    }

    private function validateMission(): bool
    {
        $fleet = $this->sessionShips();
        $mission = $this->cleanInput['mission'];

        if ($mission === 0) {
            $this->redirectTo('game.php?page=fleet1');
        }

        if (!$this->isMissionShipValid($mission, $fleet)) {
            return false;
        }

        $this->applyMissionMessages($mission, $fleet);

        if ($mission === Missions::EXPEDITION && !$this->occupiedPlanet) {
            $this->validateExpedition();
        } elseif ($mission !== Missions::COLONIZE && !$this->occupiedPlanet) {
            return false;
        }

        $this->fleetData['fleet_mission'] = $mission;

        return true;
    }

    /**
     * Missions that are outright impossible for the ship mix / ownership.
     *
     * @param  array<int, int>  $fleet
     */
    private function isMissionShipValid(int $mission, array $fleet): bool
    {
        return match ($mission) {
            Missions::ATTACK => !$this->ownPlanet,
            Missions::SPY => isset($fleet[Ships::ship_espionage_probe]) && !$this->ownPlanet,
            Missions::COLONIZE => isset($fleet[Ships::ship_colony_ship]),
            Missions::RECYCLE => !$this->isEmptyDebris(),
            Missions::DESTROY => !$this->ownPlanet
                && $this->occupiedPlanet
                && $this->asInt($this->sessionTarget()['type'] ?? 0) === PlanetTypes::MOON
                && isset($fleet[Ships::ship_deathstar]),
            default => true,
        };
    }

    /**
     * Missions whose failure shows a message page instead of a silent bounce.
     *
     * @param  array<int, int>  $fleet
     */
    private function applyMissionMessages(int $mission, array $fleet): void
    {
        if ($mission === Missions::DEPLOY && !$this->ownPlanet) {
            $this->showMessage($this->formatService->colorRed((string) __('game/fleet.fl_deploy_only_your_planets')));
        }

        if ($mission === Missions::STAY && !$this->isStayAllowed()) {
            $this->showMessage($this->formatService->colorRed((string) __('game/fleet.fl_stay_not_on_enemy')));
        }

        if ($mission === Missions::COLONIZE && isset($fleet[Ships::ship_colony_ship]) && $this->occupiedPlanet) {
            $this->showMessage($this->formatService->colorRed((string) __('game/fleet.fl_planet_populed')));
        }
    }

    private function isStayAllowed(): bool
    {
        if ($this->asInt($this->targetData['ally_id'] ?? 0) === $this->userInt('ally_id')) {
            return true;
        }

        $targetUserId = $this->asInt($this->targetData['planet_user_id'] ?? 0);
        $buddyRow = DB::selectOne(
            $this->prepareSql(
                'SELECT COUNT(*) AS buddies FROM `' . BUDDY . '`
                WHERE ((`buddy_sender` = ? AND `buddy_receiver` = ?) OR (`buddy_sender` = ? AND `buddy_receiver` = ?))
                    AND `buddy_status` = 1;'
            ),
            [$this->planetInt('planet_user_id'), $targetUserId, $targetUserId, $this->planetInt('planet_user_id')]
        );

        return (is_object($buddyRow) ? $this->asInt(get_object_vars($buddyRow)['buddies'] ?? 0) : 0) >= 1;
    }

    private function isEmptyDebris(): bool
    {
        if ($this->targetData === []) {
            return true;
        }

        return $this->asInt($this->targetData['planet_debris_metal'] ?? 0) === 0
            && $this->asInt($this->targetData['planet_debris_crystal'] ?? 0) === 0
            && time() > ($this->asInt($this->targetData['planet_invisible_start_time'] ?? 0) + DEBRIS_LIFE_TIME);
    }

    private function validateExpedition(): void
    {
        $maxExpeditions = $this->fleetsService->getMaxExpeditions(
            $this->research->getCurrentResearch()->getResearchAstrophysics()
        );

        if ($maxExpeditions <= 0) {
            $this->showMessage($this->formatService->colorRed((string) __('game/fleet.fl_expedition_tech_required')));
        }

        if ($maxExpeditions <= $this->fleets->getExpeditionsCount()) {
            $this->showMessage($this->formatService->colorRed((string) __('game/fleet.fl_expedition_fleets_limit')));
        }
    }

    private function validateNoobProtection(): bool
    {
        if ($this->ownPlanet || !$this->occupiedPlanet || $this->userLibrary->isInactive($this->targetData)) {
            return true;
        }

        $noob = new NoobsProtectionLib();
        $points = $noob->returnPoints($this->userInt('id'), $this->asInt($this->targetData['id'] ?? 0));
        $userPoints = $this->asInt($points['user_points'] ?? 0);
        $targetPoints = $this->asInt($points['target_points'] ?? 0);
        $mission = $this->cleanInput['mission'];

        $disallowWeak = [Missions::ATTACK, Missions::ACS, Missions::SPY, Missions::DESTROY];
        $disallowStrong = [Missions::ATTACK, Missions::ACS, Missions::STAY, Missions::SPY, Missions::DESTROY];

        if ($noob->isWeak($userPoints, $targetPoints) && in_array($mission, $disallowWeak, true)) {
            $this->showMessage($this->formatService->customColor((string) __('game/fleet.fl_week_player'), 'lime'));
        }

        if ($noob->isStrong($userPoints, $targetPoints) && in_array($mission, $disallowStrong, true)) {
            $this->showMessage($this->formatService->colorRed((string) __('game/fleet.fl_strong_player')));
        }

        return true;
    }

    private function validateFleets(): bool
    {
        $maxFleets = $this->fleetsService->getMaxFleets(
            $this->research->getCurrentResearch()->getResearchComputerTechnology(),
            $this->officerService->isOfficerActive($this->premium->getCurrentPremium()->getPremiumOfficierAdmiral(), time())
        );

        if ($maxFleets <= $this->fleets->getFleetsCount()) {
            $this->showMessage((string) __('game/fleet.fl_no_slots'));
        }

        return true;
    }

    private function validateResources(): bool
    {
        $metal = max(0, $this->cleanInput['resource1']);
        $crystal = max(0, $this->cleanInput['resource2']);
        $deuterium = max(0, $this->cleanInput['resource3']);

        if ($metal + $crystal + $deuterium < 1 && $this->cleanInput['mission'] === Missions::TRANSPORT) {
            $this->showMessage($this->formatService->customColor((string) __('game/fleet.fl_empty_transport'), 'lime'));
        }

        $consumption = $this->sessionFloat('consumption');
        $storageNeeded = $metal + $crystal + $deuterium;

        $stockMetal = (float) $this->planetInt('planet_metal');
        $stockCrystal = (float) $this->planetInt('planet_crystal');
        $stockDeuterium = (float) $this->planetInt('planet_deuterium') - $consumption;

        if (!($stockMetal >= $metal && $stockCrystal >= $crystal && $stockDeuterium >= $deuterium)) {
            $this->showMessage($this->formatService->colorRed(
                (string) __('game/fleet.fl_no_enought_deuterium') . $this->formatService->prettyNumber((int) $consumption)
            ));
        }

        if ($storageNeeded > $this->fleetStorage) {
            $this->showMessage($this->formatService->colorRed(
                (string) __('game/fleet.fl_no_enought_cargo_capacity') . $this->formatService->prettyNumber($storageNeeded - $this->fleetStorage)
            ));
        }

        $this->fleetData['fleet_resource_metal'] = $metal;
        $this->fleetData['fleet_resource_crystal'] = $crystal;
        $this->fleetData['fleet_resource_deuterium'] = $deuterium;
        $this->fleetData['fleet_fuel'] = (int) $consumption;

        return true;
    }

    private function validateTime(): bool
    {
        $duration = (int) floor($this->fleetsService->missionDuration(
            $this->sessionInt('speed'),
            $this->sessionFloat('fleet_speed'),
            $this->sessionInt('distance'),
            Functions::fleetSpeedFactor()
        ));

        $baseTime = time();
        $startTime = $duration + $baseTime;
        $stayDuration = 0;
        $stayTime = 0;

        if ($this->cleanInput['mission'] === Missions::EXPEDITION) {
            $stayDuration = $this->cleanInput['expeditiontime'] * 3600;
            $stayTime = $startTime + $stayDuration;
        }

        if ($this->cleanInput['mission'] === Missions::STAY) {
            $stayDuration = $this->cleanInput['holdingtime'] * 3600;
            $stayTime = $startTime + $stayDuration;
        }

        $endTime = $stayDuration + (2 * $duration) + $baseTime;
        $group = $this->asInt($this->sessionTarget()['group'] ?? 0);

        if ($group !== 0) {
            [$startTime, $endTime] = $this->syncAcsGroupTimes($group, $startTime, $endTime);
        }

        $this->fleetData['fleet_start_time'] = $startTime;
        $this->fleetData['fleet_end_time'] = $endTime;
        $this->fleetData['fleet_end_stay'] = $stayTime;

        return true;
    }

    /**
     * Align this fleet with the other fleets already flying in the ACS group.
     *
     * @return array{0: int, 1: int}
     */
    private function syncAcsGroupTimes(int $group, int $startTime, int $endTime): array
    {
        $row = DB::selectOne(
            $this->prepareSql('SELECT MAX(`fleet_start_time`) AS start_time FROM `' . FLEETS . '` WHERE `fleet_group` = ?;'),
            [$group]
        );
        $acsStartTime = is_object($row) ? $this->asInt(get_object_vars($row)['start_time'] ?? 0) : 0;

        if ($acsStartTime >= $startTime) {
            $endTime += $acsStartTime - $startTime;
            $startTime = $acsStartTime;

            return [$startTime, $endTime];
        }

        DB::update(
            $this->prepareSql(
                'UPDATE `' . FLEETS . '` SET `fleet_start_time` = ?, `fleet_end_time` = `fleet_end_time` + ?
                WHERE `fleet_group` = ?;'
            ),
            [$startTime, $startTime - $acsStartTime, $group]
        );

        return [$startTime, $endTime + ($startTime - $acsStartTime)];
    }

    private function sendFleet(): void
    {
        DB::transaction(function (): void {
            $assignments = array_map(fn (string $column): string => '`' . $column . '` = ?', array_keys($this->fleetData));

            DB::insert(
                $this->prepareSql(
                    'INSERT INTO `' . FLEETS . '` SET ' . implode(', ', $assignments) . ", `fleet_creation` = '" . time() . "';"
                ),
                array_values($this->fleetData)
            );

            $shipAssignments = [];
            $bindings = [];

            foreach ($this->fleetShips as $column => $amount) {
                $shipAssignments[] = '`' . $column . '` = `' . $column . '` - ?';
                $bindings[] = $amount;
            }

            $deuteriumSpent = $this->asInt($this->fleetData['fleet_resource_deuterium']) + $this->asInt($this->fleetData['fleet_fuel']);
            $bindings[] = $this->asInt($this->fleetData['fleet_resource_metal']);
            $bindings[] = $this->asInt($this->fleetData['fleet_resource_crystal']);
            $bindings[] = $deuteriumSpent;
            $bindings[] = $this->planetInt('planet_id');

            DB::update(
                $this->prepareSql(
                    'UPDATE `' . PLANETS . '` AS p
                    INNER JOIN `' . SHIPS . '` AS s ON s.`ship_planet_id` = p.`planet_id` SET
                    ' . implode(', ', $shipAssignments) . ',
                    `planet_metal` = `planet_metal` - ?, `planet_crystal` = `planet_crystal` - ?,
                    `planet_deuterium` = `planet_deuterium` - ?
                    WHERE `planet_id` = ?;'
                ),
                $bindings
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPlanetShips(): array
    {
        $planetId = $this->planetInt('planet_id');

        $row = $planetId > 0 ? DB::selectOne(
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

        return is_object($row) ? get_object_vars($row) : [];
    }

    private function acsGroupCount(int $group): int
    {
        $row = DB::selectOne(
            $this->prepareSql('SELECT COUNT(`acs_id`) AS `acs_amount` FROM `' . ACS . '` WHERE `acs_id` = ?;'),
            [$group]
        );

        return is_object($row) ? $this->asInt(get_object_vars($row)['acs_amount'] ?? 0) : 0;
    }

    /**
     * @return array<int, int>
     */
    private function sessionShips(): array
    {
        $data = $this->sessionFleetData();

        if (!isset($data['fleetarray']) || !is_string($data['fleetarray'])) {
            $this->redirectTo('game.php?page=fleet1');
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

    private function sessionInt(string $key): int
    {
        return $this->asInt($this->sessionFleetData()[$key] ?? 0);
    }

    private function sessionFloat(string $key): float
    {
        $value = $this->sessionFleetData()[$key] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function showMessage(string $message): void
    {
        Functions::message($message, self::REDIRECT_TARGET, 3);
    }

    private function redirectTo(string $route): never
    {
        throw new HttpResponseException(redirect($route));
    }

    private function rangeInt(Request $request, string $key, int $min, int $max): int
    {
        if (!$request->has($key) || !is_numeric($request->post($key))) {
            return 0;
        }

        $value = $request->integer($key);

        return $value >= $min && $value <= $max ? $value : 0;
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
