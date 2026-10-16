<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Support\FleetShips;
use Illuminate\Support\Facades\DB;
use App\Core\Concerns\BindsQueries;
use App\Libraries\StatisticsLibrary;
use App\Libraries\UpdatesLibrary;

/**
 * Base class of the mission handlers: shared fleet helpers and the raw SQL
 * used by the handlers.
 *
 * Values coming from database rows are untyped (mixed) at this legacy
 * boundary, so the query helpers bind every value as a parameter, rendered
 * exactly as the former string interpolation did. The SQL semantics are
 * unchanged, but the driver escapes the values instead of splicing them into
 * the query text.
 *
 * @phpstan-type Coords3 array{galaxy: mixed, system: mixed, planet: mixed}
 * @phpstan-type Coords4 array{galaxy: mixed, system: mixed, planet: mixed, type: mixed}
 * @phpstan-type Resources array{metal: mixed, crystal: mixed, deuterium: mixed}
 * @phpstan-type Row array<string, mixed>
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
class Missions
{
    use BindsQueries;

    /** @var array<int, string> */
    protected array $resource;
    /** @var array<int, array<string, mixed>> */
    protected array $pricelist;
    /** @var array<int, array<string, mixed>> */
    protected array $combatCaps;

    public function __construct()
    {
        $this->resource = MissionObjects::names();
        $this->pricelist = MissionObjects::prices();
        $this->combatCaps = MissionObjects::combatSpecs();
    }

    protected function removeFleet(int $fleetId): void
    {
        $this->deleteFleetById($fleetId);
    }

    protected function returnFleet(int $fleetId): void
    {
        $this->updateFleetStatusToReturnById($fleetId);
    }

    /**
     * Land the fleet's ships and cargo on its start planet.
     *
     * @param Row $fleetRow
     */
    protected function restoreFleet(array $fleetRow): void
    {
        $this->landFleet($fleetRow, self::fleetCoords($fleetRow, true), 0);
    }

    /**
     * Land the fleet's ships and cargo on its target planet; a deployment
     * gets half of its fuel back.
     *
     * @param Row $fleetRow
     */
    protected function restoreFleetAtTarget(array $fleetRow): void
    {
        $fuelReturn = $fleetRow['fleet_mission'] == 4 ? self::num($fleetRow['fleet_fuel']) / 2 : 0;

        $this->landFleet($fleetRow, self::fleetCoords($fleetRow, false), $fuelReturn);
    }

    /**
     * @param Row                                                     $fleetRow
     * @param array{galaxy: int, system: int, planet: int, type: int} $coords
     */
    private function landFleet(array $fleetRow, array $coords, int | float $fuelReturn): void
    {
        $this->makeUpdate($coords['galaxy'], $coords['system'], $coords['planet'], $coords['type']);

        $shipsFields = '';

        foreach (FleetShips::decode(self::str($fleetRow['fleet_array'])) as $id => $amount) {
            $shipsFields .= '`' . $this->resource[$id] . '` = `' . $this->resource[$id] . "` + '" . $amount . "', ";
        }

        $this->updatePlanetsShipsByCoords([
            'resources' => [
                'metal' => $fleetRow['fleet_resource_metal'],
                'crystal' => $fleetRow['fleet_resource_crystal'],
                'deuterium' => (self::num($fleetRow['fleet_resource_deuterium']) + $fuelReturn),
            ],
            'ships' => $shipsFields,
            'coords' => $coords,
        ]);
    }

    /**
     * Unload the fleet's cargo on its target planet.
     *
     * @param Row $fleetRow
     */
    protected function storeResources(array $fleetRow): void
    {
        $coords = self::fleetCoords($fleetRow, false);

        $this->makeUpdate($coords['galaxy'], $coords['system'], $coords['planet'], $coords['type']);

        $this->updatePlanetResourcesByCoords([
            'resources' => [
                'metal' => $fleetRow['fleet_resource_metal'],
                'crystal' => $fleetRow['fleet_resource_crystal'],
                'deuterium' => $fleetRow['fleet_resource_deuterium'],
            ],
            'coords' => $coords,
        ]);
    }

    protected function makeUpdate(int $galaxy, int $system, int $planet, int $type): void
    {
        $targetPlanet = $this->getAllPlanetDataByCoords([
            'coords' => [
                'galaxy' => $galaxy,
                'system' => $system,
                'planet' => $planet,
                'type' => $type,
            ],
        ]);

        $targetUser = $this->getAllUserDataByUserId(
            self::int($targetPlanet['planet_user_id'] ?? 0)
        );

        UpdatesLibrary::updatePlanetResources($targetUser, $targetPlanet, time());
    }

    /**
     * @param Row $fleet
     */
    protected function canStartMission(array $fleet): bool
    {
        return ($fleet['fleet_mess'] == 0 && $fleet['fleet_start_time'] <= time() && $fleet['fleet_end_stay'] <= time());
    }

    /**
     * @param Row $fleet
     */
    protected function canCompleteMission(array $fleet): bool
    {
        return ($fleet['fleet_end_time'] <= time());
    }

    protected function deleteFleetById(int $fleedId): void
    {
        if ($fleedId > 0) {
            $this->statement(
                'DELETE FROM `' . FLEETS . '` WHERE `fleet_id` = ?',
                [$fleedId]
            );
        }
    }

    protected function updateFleetStatusToReturnById(int $fleedId): void
    {
        if ($fleedId > 0) {
            $this->statement(
                'UPDATE ' . FLEETS . " SET
                        `fleet_mess` = '1'
                    WHERE `fleet_id` = ?",
                [$fleedId]
            );
        }
    }

    protected function updateFleetStatusToStayById(mixed $fleedId): void
    {
        if (self::int($fleedId) > 0) {
            $this->statement(
                'UPDATE ' . FLEETS . " SET
                        `fleet_mess` = '2'
                    WHERE `fleet_id` = ?",
                [$fleedId]
            );
        }
    }

    /**
     * @param array{resources: Resources, ships: mixed, coords: Coords4} $data
     */
    protected function updatePlanetsShipsByCoords(array $data): void
    {
        $this->statement(
            'UPDATE ' . PLANETS . ' AS p
                    INNER JOIN ' . SHIPS . ' AS s ON s.ship_planet_id = p.`planet_id` SET
                        ' . self::str($data['ships']) . '
                        `planet_metal` = `planet_metal` + ?,
                        `planet_crystal` = `planet_crystal` + ?,
                        `planet_deuterium` = `planet_deuterium` + ?
                    WHERE `planet_galaxy` = ? AND
                        `planet_system` = ? AND
                        `planet_planet` = ? AND
                        `planet_type` = ?',
            [
                ...self::resources($data['resources']),
                ...self::coords4($data['coords']),
            ]
        );
    }

    /**
     * @param array{resources: Resources, coords: Coords4} $data
     */
    protected function updatePlanetResourcesByCoords(array $data): void
    {
        $this->statement(
            'UPDATE ' . PLANETS . ' SET
                        `planet_metal` = `planet_metal` + ?,
                        `planet_crystal` = `planet_crystal` + ?,
                        `planet_deuterium` = `planet_deuterium` + ?
                    WHERE `planet_galaxy` = ? AND
                        `planet_system` = ? AND
                        `planet_planet` = ? AND
                        `planet_type` = ?
                    LIMIT 1;',
            [
                ...self::resources($data['resources']),
                ...self::coords4($data['coords']),
            ]
        );
    }

    /**
     * @param array{coords: Coords4} $data
     *
     * @return Row
     */
    protected function getAllPlanetDataByCoords(array $data): array
    {
        return $this->selectRow(
            'SELECT *
                    FROM `' . PLANETS . '` AS p
                    LEFT JOIN `' . BUILDINGS . '` AS b ON b.building_planet_id = p.`planet_id`
                    LEFT JOIN `' . DEFENSES . '` AS d ON d.defense_planet_id = p.`planet_id`
                    LEFT JOIN `' . SHIPS . '` AS s ON s.ship_planet_id = p.`planet_id`
                    WHERE `planet_galaxy` = ? AND
                        `planet_system` = ? AND
                        `planet_planet` = ? AND
                        `planet_type` = ?
                    LIMIT 1;',
            array_map(self::int(...), self::coords4($data['coords']))
        );
    }

    /**
     * @return Row
     */
    protected function getAllUserDataByUserId(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return $this->selectRow(
            'SELECT u.*,
                        r.*,
                        pr.*,
                        pref.preference_vacation_mode,
                        pref.preference_fleet_escape,
                        a.alliance_class
                    FROM `' . USERS . '` AS u
                    INNER JOIN `' . RESEARCH . '` AS r ON r.research_user_id = u.id
                    INNER JOIN `' . PREMIUM . '` AS pr ON pr.premium_user_id = u.id
                    INNER JOIN `' . PREFERENCES . '` AS pref ON pref.preference_user_id = u.id
                    LEFT JOIN `' . ALLIANCE . '` AS a ON a.alliance_id = u.ally_id
                    WHERE u.`id` = ?
                    LIMIT 1;',
            [$userId]
        );
    }

    protected function deleteAcsFleetById(mixed $fleetGroupId): void
    {
        if (self::int($fleetGroupId) > 0) {
            $this->statement(
                'DELETE FROM `' . ACS . '`
                    WHERE `acs_id` = ?',
                [$fleetGroupId]
            );
        }
    }

    protected function updateAcsFleetStatusByGroupId(mixed $fleetGroupId): void
    {
        if (self::int($fleetGroupId) > 0) {
            $this->statement(
                'UPDATE `' . FLEETS . "` SET
                        `fleet_mess` = '1'
                    WHERE `fleet_group` = ?",
                [$fleetGroupId]
            );
        }
    }

    /**
     * @return list<Row>|null
     */
    protected function getAllAcsFleetsByGroupId(int $fleetGroupId): ?array
    {
        if ($fleetGroupId <= 0) {
            return null;
        }

        return $this->selectRows(
            'SELECT
                            f.*,
                            r.`research_hyperspace_technology`
                        FROM `' . FLEETS . '` f
                        LEFT JOIN `' . RESEARCH . '` r
                            ON r.`research_user_id` = f.`fleet_owner`
                        WHERE f.`fleet_group` = ?;',
            [$fleetGroupId]
        );
    }

    /**
     * @param array{coords: Coords4, time: mixed} $data
     *
     * @return list<Row>
     */
    protected function getAllFleetsByEndCoordsAndTimes(array $data): array
    {
        return $this->selectRows(
            'SELECT *
                        FROM `' . FLEETS . '`
                        WHERE `fleet_end_galaxy` = ? AND
                            `fleet_end_system` = ? AND
                            `fleet_end_planet` = ? AND
                            `fleet_end_type` = ? AND
                            `fleet_start_time` < ? AND
                            `fleet_end_stay` >= ?;',
            [
                ...array_map(self::int(...), self::coords4($data['coords'])),
                $data['time'],
                $data['time'],
            ]
        );
    }

    /**
     * @param array{time: mixed, debris: array{metal: mixed, crystal: mixed}, coords: Coords3} $data
     */
    protected function updatePlanetDebrisByCoords(array $data): void
    {
        $this->statement(
            'UPDATE ' . PLANETS . ' SET
                        `planet_invisible_start_time` = ?,
                        `planet_debris_metal` = `planet_debris_metal` + ?,
                        `planet_debris_crystal` = `planet_debris_crystal` + ?
                    WHERE `planet_galaxy` = ? AND
                        `planet_system` = ? AND
                        `planet_planet` = ? AND
                        `planet_type` = 1
                    LIMIT 1;',
            [
                $data['time'],
                $data['debris']['metal'],
                $data['debris']['crystal'],
                ...array_map(self::int(...), self::coords3($data['coords'])),
            ]
        );
    }

    /**
     * @return Row
     */
    protected function getTechnologiesByUserId(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return $this->selectRow(
            'SELECT u.name,
                        u.player_class,
                        u.ally_id,
                        a.alliance_class,
                        r.research_weapons_technology,
                        r.research_shielding_technology,
                        r.research_armour_technology,
                        r.research_hyperspace_technology
                    FROM ' . USERS . ' AS u
                    INNER JOIN `' . RESEARCH . '` AS r
                        ON r.research_user_id = u.id
                    LEFT JOIN `' . ALLIANCE . '` AS a ON a.alliance_id = u.ally_id
                    WHERE u.id = ?;',
            [$userId]
        );
    }

    /**
     * @param array{coords: Coords3} $data
     *
     * @return Row
     */
    protected function getMoonIdByCoords(array $data): array
    {
        return $this->selectRow(
            'SELECT `planet_id`
                    FROM `' . PLANETS . "`
                    WHERE `planet_galaxy` = ?
                        AND `planet_system` = ?
                        AND `planet_planet` = ?
                        AND `planet_type` = '3';",
            self::coords3($data['coords'])
        );
    }

    /**
     * @param array{owners: mixed, rid: mixed, content: mixed, destroyed: mixed, time: mixed} $data
     */
    protected function insertReport(array $data): void
    {
        $this->statement(
            'INSERT INTO `' . REPORTS . '` SET
                    `report_owners` = ?,
                    `report_rid` = ?,
                    `report_content` = ?,
                    `report_destroyed` = ?,
                    `report_time` = ?',
            [
                $data['owners'],
                $data['rid'],
                $data['content'],
                $data['destroyed'],
                $data['time'],
            ]
        );
    }

    /**
     * @param array{ships: mixed, amount: mixed, stolen: Resources, fleet_id: mixed} $data
     */
    protected function updateReturningFleetData(array $data): void
    {
        $this->statement(
            'UPDATE `' . FLEETS . "` SET
                    `fleet_array` = ?,
                    `fleet_amount` = ?,
                    `fleet_mess` = '1',
                    `fleet_resource_metal` = `fleet_resource_metal` + ? ,
                    `fleet_resource_crystal` = `fleet_resource_crystal` + ? ,
                    `fleet_resource_deuterium` = `fleet_resource_deuterium` + ?
                    WHERE `fleet_id` = ?;",
            [
                $data['ships'],
                $data['amount'],
                ...self::resources($data['stolen']),
                $data['fleet_id'],
            ]
        );
    }

    protected function deleteMultipleFleetsByIds(string $idString): void
    {
        DB::statement(
            $this->prepareSql(
                'DELETE FROM `' . FLEETS . '`
                WHERE `fleet_id` IN (' . $idString . ')'
            )
        );
    }

    /**
     * @param array{ships: mixed, stolen: Resources, planet_id: mixed} $data
     */
    protected function updatePlanetLossesById(array $data): void
    {
        $this->statement(
            'UPDATE `' . PLANETS . '`, `' . SHIPS . '`, `' . DEFENSES . '`  SET
                    ' . self::str($data['ships']) . '
                    `planet_metal` = `planet_metal` -  ?,
                    `planet_crystal` = `planet_crystal` -  ?,
                    `planet_deuterium` = `planet_deuterium` -  ?
                    WHERE `planet_id` = ? AND
                        `ship_planet_id` = ? AND
                        `defense_planet_id` = ?',
            [
                ...self::resources($data['stolen']),
                $data['planet_id'],
                $data['planet_id'],
                $data['planet_id'],
            ]
        );
    }

    /**
     * @param array{id: mixed, coords: Coords3} $data
     *
     * @return Row
     */
    protected function getPlanetAndUserCountsCounts(array $data): array
    {
        return $this->selectRow(
            'SELECT
                        (SELECT COUNT(*)
                            FROM ' . PLANETS . " AS pc1
                            WHERE pc1.`planet_user_id` = ? AND
                                            pc1.`planet_type` = '1' AND
                                            pc1.`planet_destroyed` = '0') AS planet_count,
                        (SELECT COUNT(*)
                            FROM " . PLANETS . " AS pc2
                            WHERE pc2.`planet_galaxy` = ? AND
                                            pc2.`planet_system` = ? AND
                                            pc2.`planet_planet` = ? AND
                                            pc2.`planet_type` = '1') AS galaxy_count,
                        (SELECT `research_astrophysics`
                            FROM " . RESEARCH . '
                            WHERE `research_user_id` = ?) AS astro_level',
            [
                $data['id'],
                ...self::coords3($data['coords']),
                $data['id'],
            ]
        );
    }

    /**
     * @param array{coords: array{start: Coords4, end: Coords4}} $data
     *
     * @return Row
     */
    protected function getFriendlyPlanetData(array $data): array
    {
        return $this->selectRow(
            'SELECT
                        pc1.`planet_user_id` AS `start_id`,
                        pc1.`planet_name` AS `start_name`,
                        pc2.`planet_user_id` AS `target_id`,
                        pc2.`planet_name` AS `target_name`,
                        pc2.`planet_metal` AS `target_metal`,
                        pc2.`planet_crystal` AS `target_crystal`,
                        pc2.`planet_deuterium` AS `target_deuterium`,
                        u.`name` AS `start_user_name`
                    FROM `' . PLANETS . '` AS pc1 JOIN `' . PLANETS . '` AS pc2
                    LEFT JOIN `' . USERS . '` AS u
                        ON u.`id` = pc1.`planet_user_id`
                    WHERE pc1.planet_galaxy = ? AND
                        pc1.`planet_system` = ? AND
                        pc1.`planet_planet` = ? AND
                        pc1.`planet_type` = ? AND
                        pc2.`planet_galaxy` = ? AND
                        pc2.`planet_system` = ? AND
                        pc2.`planet_planet` = ? AND
                        pc2.`planet_type` = ?',
            [
                ...self::coords4($data['coords']['start']),
                ...self::coords4($data['coords']['end']),
            ]
        );
    }

    /**
     * @param array<int, int|float> $lost lost unit counts by unit id
     */
    protected function updateLostShipsAndDefensePoints(int $playerId, array $lost): void
    {
        $shipPoints = 0;
        $defensePoints = 0;

        foreach ($lost as $unit => $lostCount) {
            $points = StatisticsLibrary::calculatePoints($unit, 1) * $lostCount;

            if ($unit >= 401) {
                $defensePoints += $points;
                continue;
            }

            $shipPoints += $points;
        }

        $this->statement(
            'UPDATE `' . USERS_STATISTICS . '` AS us SET
                    us.`user_statistic_ships_points` = us.`user_statistic_ships_points` - ? ,
                    us.`user_statistic_defenses_points` = us.`user_statistic_defenses_points` - ?,
                    us.`user_statistic_military_points` = us.`user_statistic_military_points` - ?
                WHERE us.`user_statistic_user_id` = ?',
            [$shipPoints, $defensePoints, ($shipPoints + $defensePoints), $playerId]
        );
    }

    /**
     * @param array{points: mixed, coords: Coords4} $data
     */
    protected function updateColonizationStatistics(array $data): void
    {
        $points = self::int($data['points']);

        $this->statement(
            'UPDATE ' . USERS_STATISTICS . ' AS us SET
                    us.`user_statistic_ships_points` = us.`user_statistic_ships_points` - ' . $points . ',
                    us.`user_statistic_military_points` = us.`user_statistic_military_points` - ' . $points . '
                    WHERE us.`user_statistic_user_id` = (
                        SELECT p.planet_user_id FROM ' . PLANETS . ' AS p
                        WHERE p.planet_galaxy = ? AND
                            p.planet_system = ? AND
                            p.planet_planet = ? AND
                            p.planet_type = ?
                    );',
            self::coords4($data['coords'])
        );
    }

    /**
     * @param array{ships: mixed, points: mixed, fleet_id: mixed, coords: Coords4} $data
     */
    protected function updateColonizatonReturningFleet(array $data): void
    {
        $points = self::int($data['points']);

        $this->statement(
            'UPDATE ' . FLEETS . ', ' . USERS_STATISTICS . " SET
                    `fleet_array` = ?,
                    `fleet_amount` = `fleet_amount` - 1,
                    `fleet_resource_metal` = '0',
                    `fleet_resource_crystal` = '0',
                    `fleet_resource_deuterium` = '0',
                    `fleet_mess` = '1',
                    `user_statistic_ships_points` = `user_statistic_ships_points` - " . $points . ',
                    `user_statistic_military_points` = `user_statistic_military_points` - ' . $points . '
                    WHERE `fleet_id` = ? AND
                        `user_statistic_user_id` = (
                        SELECT planet_user_id FROM ' . PLANETS . '
                        WHERE planet_galaxy = ? AND
                            planet_system = ? AND
                            planet_planet = ? AND
                            planet_type = ?
                    );',
            [
                $data['ships'],
                $data['fleet_id'],
                ...self::coords4($data['coords']),
            ]
        );
    }

    /**
     * @param array{coords: Coords3, time: mixed, planet_id: mixed} $data
     */
    protected function updateFleetsStatusToMakeThemReturn(array $data): void
    {
        $coords = [$data['coords']['galaxy'], $data['coords']['system'], $data['coords']['planet']];

        $this->statement(
            'UPDATE `' . FLEETS . "` AS f SET
                        f.`fleet_start_type` = '1'
                    WHERE f.`fleet_start_galaxy` = ?
                        AND f.`fleet_start_system` = ?
                        AND f.`fleet_start_planet` = ?;",
            $coords
        );

        $this->statement(
            'UPDATE `' . FLEETS . "` AS f SET
                        f.`fleet_end_type` = '1'
                    WHERE f.`fleet_end_galaxy` = ?
                        AND f.`fleet_end_system` = ?
                        AND f.`fleet_end_planet` = ?;",
            $coords
        );

        $this->statement(
            'UPDATE `' . PLANETS . '` AS p SET
                        `planet_destroyed` = ?
                    WHERE p.`planet_id` = ?;',
            [$data['time'], $data['planet_id']]
        );
    }

    /**
     * Point the user's current planet at the planet (type 1) on the given coords.
     *
     * Bugfix: the coords used to be read from keys that no caller provides
     * ('fleet_end_galaxy', ...), so the call raised an "undefined array key"
     * error instead of switching away from a destroyed moon.
     *
     * @param array{coords: Coords3, planet_user_id: mixed} $data
     */
    protected function updateUserCurrentPlanetByCoordsAndUserId(array $data): void
    {
        $this->statement(
            'UPDATE ' . USERS . ' SET
                        `current_planet` = (
                            SELECT `planet_id`
                            FROM ' . PLANETS . "
                            WHERE `planet_galaxy` = ? AND
                                `planet_system` = ? AND
                                `planet_planet` = ? AND
                                `planet_type` = '1')
                    WHERE `id` = ?;",
            [
                ...self::coords3($data['coords']),
                $data['planet_user_id'],
            ]
        );
    }

    /**
     * Replace the ships of a fleet that stays where it is.
     *
     * @param array{ships: string, amount: int|float, fleet_id: int} $data
     */
    protected function updateFleetShipsById(array $data): void
    {
        $this->statement(
            'UPDATE ' . FLEETS . ' SET `fleet_array` = ?, `fleet_amount` = ? WHERE `fleet_id` = ?;',
            [$data['ships'], $data['amount'], $data['fleet_id']]
        );
    }

    /**
     * Replace the ships of a fleet and send it home.
     *
     * @param array{ships: mixed, amount: int|float, fleet_id: mixed} $data
     */
    protected function updateFleetArrayById(array $data): void
    {
        $this->statement(
            'UPDATE ' . FLEETS . " SET
                    `fleet_array` = ?,
                    `fleet_amount` = ?,
                    `fleet_mess` = '1'
                    WHERE `fleet_id` = ?;",
            [$data['ships'], $data['amount'], self::int($data['fleet_id'])]
        );
    }

    protected function updateFleetResourcesById(int $fleetId, string $resource, int $amount): void
    {
        $this->statement(
            'UPDATE ' . FLEETS . " AS f SET
                `fleet_resource_{$resource}` = `fleet_resource_{$resource}` + ?,
                `fleet_mess` = '1'
                WHERE `fleet_id` = ?;",
            [$amount, $fleetId]
        );
    }

    protected function updateDarkMatter(int $userId, int $darkMatter): void
    {
        $this->statement(
            'UPDATE ' . PREMIUM . ' AS p SET
                `premium_dark_matter` = `premium_dark_matter` + ?
                WHERE `premium_user_id` = ?;',
            [$darkMatter, $userId]
        );
    }

    protected function updateFleetEndTime(int $fleetId, int $fleetEndTime): void
    {
        $this->statement(
            'UPDATE ' . FLEETS . " AS f SET
                `fleet_end_time` = ?,
                `fleet_mess` = '1'
                WHERE `fleet_id` = ?;",
            [$fleetEndTime, $fleetId]
        );
    }

    /**
     * @param array{coords: Coords4} $data
     *
     * @return Row
     */
    protected function getMissileAttackerDataByCoords(array $data): array
    {
        return $this->selectRow(
            'SELECT p.`planet_name`, r.`research_weapons_technology`
                    FROM ' . PLANETS . ' AS p
                    INNER JOIN ' . RESEARCH . ' AS r ON r.research_user_id = p.planet_user_id
                    WHERE `planet_galaxy` = ? AND
                        `planet_system` = ? AND
                        `planet_planet` = ? AND
                        `planet_type` = ?;',
            self::coords4($data['coords'])
        );
    }

    /**
     * @param array{coords: Coords4} $data
     *
     * @return Row
     */
    protected function getMissileTargetDataByCoords(array $data): array
    {
        return $this->selectRow(
            'SELECT p.`planet_id`, p.`planet_name`, p.`planet_user_id`, d.*, r.`research_shielding_technology`
                    FROM ' . PLANETS . ' AS p
                    INNER JOIN ' . DEFENSES . ' AS d ON d.defense_planet_id = p.`planet_id`
                    INNER JOIN ' . RESEARCH . ' AS r ON r.research_user_id = p.planet_user_id
                    WHERE `planet_galaxy` = ? AND
                        `planet_system` = ? AND
                        `planet_planet` = ? AND
                        `planet_type` = ?;',
            self::coords4($data['coords'])
        );
    }

    /**
     * @param array{destroyed_query: mixed, amount: mixed, planet_id: mixed} $data
     */
    protected function updatePlanetDefenses(array $data): void
    {
        $this->statement(
            'UPDATE ' . DEFENSES . ' SET
                    ' . self::str($data['destroyed_query']) . '
                    `defense_anti-ballistic_missile` = ?
                    WHERE defense_planet_id = ?;',
            [$data['amount'], self::int($data['planet_id'])]
        );
    }

    /**
     * @param array{recycled: array{metal: mixed, crystal: mixed}, coords: Coords3, fleet_id: mixed} $data
     */
    protected function updatePlanetDebrisFieldAndFleet(array $data): void
    {
        $this->statement(
            'UPDATE ' . PLANETS . ', ' . FLEETS . " SET
                    `planet_debris_metal` = `planet_debris_metal` - ?,
                    `planet_debris_crystal` = `planet_debris_crystal` - ?,
                    `fleet_resource_metal` = `fleet_resource_metal` + ?,
                    `fleet_resource_crystal` = `fleet_resource_crystal` + ?,
                    `fleet_mess` = '1'
                    WHERE `planet_galaxy` = ? AND
                        `planet_system` = ? AND
                        `planet_planet` = ? AND
                        `planet_type` = 1 AND
                        `fleet_id` = ?",
            [
                $data['recycled']['metal'],
                $data['recycled']['crystal'],
                $data['recycled']['metal'],
                $data['recycled']['crystal'],
                ...self::coords3($data['coords']),
                self::int($data['fleet_id']),
            ]
        );
    }

    /**
     * @param array{coords: Coords3} $data
     *
     * @return Row
     */
    protected function getPlanetDebris(array $data): array
    {
        return $this->selectRow(
            'SELECT
                        `planet_name` AS target_name,
                        `planet_debris_metal`,
                        `planet_debris_crystal`
                    FROM `' . PLANETS . '`
                    WHERE `planet_galaxy` = ? AND
                        `planet_system` = ? AND
                        `planet_planet` = ? AND
                        `planet_type` = 1
                    LIMIT 1;',
            self::coords3($data['coords'])
        );
    }

    /**
     * @param array{coords: Coords4} $data
     *
     * @return Row
     */
    protected function getSpyUserDataByCords(array $data): array
    {
        return $this->selectRow(
            'SELECT
                        p.`planet_name`,
                        p.`planet_galaxy`,
                        p.`planet_system`,
                        p.`planet_planet`,
                        u.`name`,
                        r.`research_espionage_technology`,
                        pr.`premium_officier_technocrat`,
                        a.`alliance_class`
                    FROM `' . PLANETS . '` AS p
                    INNER JOIN `' . USERS . '` AS u ON u.`id` = p.`planet_user_id`
                    INNER JOIN `' . PREMIUM . '` AS pr ON pr.`premium_user_id` = p.`planet_user_id`
                    INNER JOIN `' . RESEARCH . '` AS r ON r.`research_user_id` = p.`planet_user_id`
                    LEFT JOIN `' . ALLIANCE . '` AS a ON a.`alliance_id` = u.`ally_id`
                    WHERE p.`planet_galaxy` = ? AND
                        p.`planet_system` = ? AND
                        p.`planet_planet` = ? AND
                        p.`planet_type` = ?;',
            self::coords4($data['coords'])
        );
    }

    /**
     * @param array{coords: Coords4} $data
     *
     * @return Row
     */
    protected function getInquiredUserDataByCords(array $data): array
    {
        return $this->selectRow(
            'SELECT
                        p.`planet_id`,
                        p.`planet_user_id`,
                        p.`planet_name`,
                        p.`planet_galaxy`,
                        p.`planet_system`,
                        p.`planet_planet`,
                        p.planet_metal,
                        p.`planet_crystal`,
                        p.`planet_deuterium`,
                        p.`planet_energy_max`,
                        s.*, d.*, b.*, r.*,
                        pr.`premium_officier_technocrat`,
                        a.`alliance_class`
                    FROM `' . PLANETS . '` AS p
                    INNER JOIN `' . SHIPS . '` AS s ON s.`ship_planet_id` = p.`planet_id`
                    INNER JOIN `' . DEFENSES . '` AS d ON d.`defense_planet_id` = p.`planet_id`
                    INNER JOIN `' . BUILDINGS . '` AS b ON b.`building_planet_id` = p.`planet_id`
                    INNER JOIN `' . USERS . '` AS u ON u.`id` = p.`planet_user_id`
                    INNER JOIN `' . PREMIUM . '` AS pr ON pr.`premium_user_id` = p.`planet_user_id`
                    INNER JOIN `' . RESEARCH . '` AS r ON r.`research_user_id` = p.`planet_user_id`
                    LEFT JOIN `' . ALLIANCE . '` AS a ON a.`alliance_id` = u.`ally_id`
                    WHERE p.`planet_galaxy` = ? AND
                        p.`planet_system` = ? AND
                        p.`planet_planet` = ? AND
                        p.`planet_type` = ?;',
            self::coords4($data['coords'])
        );
    }

    /**
     * @param array{time: mixed, crystal: mixed, planet_id: mixed} $data
     */
    protected function updateCrystalDebrisByPlanetId(array $data): void
    {
        $this->statement(
            'UPDATE ' . PLANETS . ' SET
                    `planet_invisible_start_time` = ?,
                    `planet_debris_crystal` = `planet_debris_crystal` + ?
                    WHERE `planet_id` = ?;',
            [$data['time'], $data['crystal'], $data['planet_id']]
        );
    }

    protected function updateReturningFleetResources(int $fleedId = 0): void
    {
        if ($fleedId > 0) {
            $this->statement(
                'UPDATE ' . FLEETS . " SET
                        `fleet_resource_metal` = '0' ,
                        `fleet_resource_crystal` = '0' ,
                        `fleet_resource_deuterium` = '0' ,
                        `fleet_mess` = '1'
                    WHERE `fleet_id` = ?
                    LIMIT 1 ;",
                [$fleedId]
            );
        }
    }

    // ------------------------------------------------------------------
    // Helpers (query/coercion helpers live in BindsQueries)
    // ------------------------------------------------------------------

    /**
     * The start or end coordinates of a fleet row.
     *
     * @param Row $fleetRow
     *
     * @return array{galaxy: int, system: int, planet: int, type: int}
     */
    private static function fleetCoords(array $fleetRow, bool $start): array
    {
        $prefix = $start ? 'fleet_start_' : 'fleet_end_';

        return [
            'galaxy' => self::int($fleetRow[$prefix . 'galaxy']),
            'system' => self::int($fleetRow[$prefix . 'system']),
            'planet' => self::int($fleetRow[$prefix . 'planet']),
            'type' => self::int($fleetRow[$prefix . 'type']),
        ];
    }

    /**
     * @param Coords3 $coords
     *
     * @return list<mixed>
     */
    private static function coords3(array $coords): array
    {
        return [$coords['galaxy'], $coords['system'], $coords['planet']];
    }

    /**
     * @param Resources $resources
     *
     * @return list<mixed>
     */
    private static function resources(array $resources): array
    {
        return [$resources['metal'], $resources['crystal'], $resources['deuterium']];
    }

    /**
     * @param Coords4 $coords
     *
     * @return list<mixed>
     */
    private static function coords4(array $coords): array
    {
        return [$coords['galaxy'], $coords['system'], $coords['planet'], $coords['type']];
    }
}
