<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\Functions;

use App\Libraries\Formulas;

use App\Models\Planets;
use App\Models\User;
use App\Services\Admin\BackupService;
use App\Services\FormatService;
use App\Services\Game\BuildingQueueService;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\Game\Formulas\ProductionService;
use App\Services\Game\ResearchQueueService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\BuildingsEnumerator as Buildings;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator;
use Xgp\App\Core\Enumerators\ResearchEnumerator as Research;
use Xgp\App\Core\Objects;
use App\Libraries\DevelopmentsLib as Developments;
use App\Libraries\MissionControlLib;
use App\Libraries\StatisticsLibrary;
use App\Libraries\Users;

/**
 * The tick: on construction it runs cleanup, backups, fleet missions and the
 * statistics rebuild; its static entry points refresh a planet's resources and
 * queues. The batch resource maths is intrinsically long and reads the untyped
 * game state through the {@see num()} coercion helper.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.UnusedLocalVariable")
 * @SuppressWarnings("PHPMD.CamelCaseVariableName")
 * @SuppressWarnings("PHPMD.CamelCaseParameterName")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class UpdatesLibrary
{
    use PreparesLegacySql;

    public function __construct()
    {
        // Other stuff
        $this->cleanUp();
        $this->createBackup();

        // Updates
        $this->updateFleets();
        $this->updateStatistics();
    }

    private function cleanUp(): void
    {
        $settings = app(SettingsService::class);
        $lastCleanup = $settings->getInt('last_cleanup');
        $cleanupInterval = 6; // 6 HOURS

        if ((time() >= ($lastCleanup + (3600 * $cleanupInterval)))) {
            // TIMERS
            $delPlanets = time() - ONE_DAY;
            $delBefore = time() - ONE_WEEK;
            $delInactive = time() - ONE_MONTH;
            $delDeleted = time() - ONE_WEEK;

            // USERS TO DELETE
            $chooseToDelete = array_map(
                fn ($row) => (array) $row,
                DB::select(
                    $this->prepareSql(
                        'SELECT u.`id`
                        FROM `' . USERS . '` AS u
                        INNER JOIN `' . PREFERENCES . "` AS p ON p.preference_user_id = u.id
                        WHERE (p.`preference_delete_mode` < '" . $delDeleted . "'
                            AND p.`preference_delete_mode` <> 0)
                            OR (u.`onlinetime` < '" . $delInactive . "' AND u.`onlinetime` <> 0 AND u.`authlevel` <> 3)"
                    )
                )
            );

            $users = new Users();

            if ($chooseToDelete) {
                foreach ($chooseToDelete as $delete) {
                    $users->deleteUser((int) $delete['id']);
                }
            }

            // Misc deletions
            DB::statement($this->prepareSql('DELETE FROM ' . MESSAGES . " WHERE `message_time` < '" . $delBefore . "';"));
            DB::statement($this->prepareSql('DELETE FROM ' . REPORTS . " WHERE `report_time` < '" . $delBefore . "';"));
            DB::table('sessions')->where('last_activity', '<', $delPlanets)->delete();
            DB::statement(
                $this->prepareSql(
                    'DELETE p,b,d,s FROM `' . PLANETS . '` AS p
                    INNER JOIN `' . BUILDINGS . '` AS b ON b.building_planet_id = p.`planet_id`
                    INNER JOIN `' . DEFENSES . '` AS d ON d.defense_planet_id = p.`planet_id`
                    INNER JOIN `' . SHIPS . "` AS s ON s.ship_planet_id = p.`planet_id`
                    WHERE `planet_destroyed` < '" . $delPlanets . "'
                        AND `planet_destroyed` <> 0;"
                )
            );
            DB::statement(
                $this->prepareSql(
                    'DELETE a,m1,m2 FROM `' . ACS . '` AS a
                    INNER JOIN `' . ACS_MEMBERS . '` m1 ON m1.`acs_group_id` = a.`acs_id`
                    RIGHT JOIN `' . ACS_MEMBERS . '` m2 ON m2.`acs_group_id` = a.`acs_id`
                    LEFT JOIN `' . FLEETS . '` f ON f.`fleet_group` = a.`acs_id`
                    WHERE f.`fleet_id` IS NULL'
                )
            );

            $settings->write('last_cleanup', time());
        }
    }

    private function createBackup(): void
    {
        $settings = app(SettingsService::class);
        $autoBackup = $settings->getBool('auto_backup');
        $lastBackup = $settings->getInt('last_backup');
        $updateInterval = 6;

        if ((time() >= ($lastBackup + (3600 * $updateInterval))) && $autoBackup) {
            app(BackupService::class)->createBackup();

            $settings->write('last_backup', time());
        }
    }

    /**
     * @param array<string, mixed> $current_planet Current planet (by reference for sync-back)
     * @param array<string, mixed> $current_user   Current user (by reference for sync-back)
     */
    public static function updateBuildingsQueue(array &$current_planet, array &$current_user): void
    {
        /** @var Planets|null $planet */
        $planet = Planets::with(['buildings'])->where('planet_id', $current_planet['planet_id'])->first();

        if (!$planet) {
            return;
        }

        app(BuildingQueueService::class)->processCompletions($planet, $current_user);

        // Sync modified scalar fields back to the flat array
        $current_planet['planet_b_building'] = $planet->planet_b_building;
        $current_planet['planet_metal'] = $planet->planet_metal;
        $current_planet['planet_crystal'] = $planet->planet_crystal;
        $current_planet['planet_deuterium'] = $planet->planet_deuterium;
        $current_planet['planet_field_current'] = $planet->planet_field_current;
        $current_planet['planet_field_max'] = $planet->planet_field_max;

        // Sync building levels from the buildings relation
        if ($planet->buildings) {
            foreach ($planet->buildings->getAttributes() as $key => $value) {
                if (array_key_exists($key, $current_planet)) {
                    $current_planet[$key] = $value;
                }
            }
        }

        // Rebuild the legacy string cache from building_queues for pages not yet migrated
        $queue = $planet->buildingQueue()->orderBy('position')->get();

        if ($queue->isEmpty()) {
            $current_planet['planet_b_building_id'] = '0';

            return;
        }

        $current_planet['planet_b_building_id'] = $queue->map(
            fn ($item) => implode(',', [
                $item->building_id,
                $item->target_level,
                $item->duration,
                $item->end_time,
                $item->mode,
            ])
        )->join(';');
    }

    /**
     * updateResearchQueue
     *
     * @param array<string,mixed> $current_planet Current planet data (by reference for sync-back)
     * @param array<string,mixed> $current_user   Current user data (by reference for sync-back)
     *
     * @return void
     */
    public static function updateResearchQueue(array &$current_planet, array &$current_user): void
    {
        $userId = self::numInt($current_user['id'] ?? 0);

        if ($userId === 0) {
            return;
        }

        /** @var User|null $user */
        $user = User::with(['research', 'researchQueue'])->find($userId);

        if ($user === null) {
            return;
        }

        app(ResearchQueueService::class)->processCompletions($user);

        // Sync research levels back to the flat user array
        foreach ($user->research->getAttributes() as $key => $value) {
            if (array_key_exists($key, $current_user)) {
                $current_user[$key] = $value;
            }
        }

        // Sync planet tech cache fields back if this planet was involved
        if (isset($current_planet['planet_id'])) {
            /** @var Planets|null $planet */
            $planet = Planets::find(self::numInt($current_planet['planet_id']));

            if ($planet !== null) {
                $current_planet['planet_b_tech_id'] = $planet->planet_b_tech_id;
                $current_planet['planet_b_tech'] = $planet->planet_b_tech;
            }
        }
    }

    /**
     * updateFleets
     *
     * @return void
     */
    private function updateFleets()
    {
        // let's start the missions control process
        $mission_control = new MissionControlLib();
        $mission_control->arrivingFleets();
        $mission_control->returningFleets();
    }

    /**
     * updateStatistics
     *
     * @return void
     */
    private function updateStatistics()
    {
        // LAST UPDATE AND UPDATE INTERVAL, EX: 15 MINUTES
        $settings = app(SettingsService::class);
        $stat_last_update = $settings->getInt('stat_last_update');
        $update_interval = $settings->getInt('stat_update_time');

        if ((time() >= ($stat_last_update + (60 * $update_interval)))) {
            $result = new StatisticsLibrary();

            $settings->write('stat_last_update', $result->makeStats()['stats_time']);
        }
    }

    private static function sql(string $sql): string
    {
        return strtr($sql, ['{xgp_prefix}' => DB::getTablePrefix()]);
    }

    /**
     * @param array<string, mixed> $current_user   Current user (by reference)
     * @param array<string, mixed> $current_planet Current planet (by reference)
     *
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     * @SuppressWarnings("PHPMD.ElseExpression")
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public static function updatePlanetResources(array &$current_user, array &$current_planet, int $UpdateTime, bool $Simul = false): void
    {
        $resource = Objects::getInstance()->getObjects();
        $ProdGrid = Objects::getInstance()->getProduction();

        $settings = app(SettingsService::class);
        $productionService = app(ProductionService::class); // Get service from container
        $officerService = app(OfficerService::class);
        $game_resource_multiplier = $settings->getInt('resource_multiplier');
        $game_metal_basic_income = $settings->getInt('metal_basic_income');
        $game_crystal_basic_income = $settings->getInt('crystal_basic_income');
        $game_deuterium_basic_income = $settings->getInt('deuterium_basic_income');

        if ($current_user['preference_vacation_mode'] > 0) {
            $game_metal_basic_income = 0;
            $game_crystal_basic_income = 0;
            $game_deuterium_basic_income = 0;
        }

        $current_planet['planet_metal_max'] = $productionService->maxStorable(self::numInt($current_planet[$resource[22]]));
        $current_planet['planet_crystal_max'] = $productionService->maxStorable(self::numInt($current_planet[$resource[23]]));
        $current_planet['planet_deuterium_max'] = $productionService->maxStorable(self::numInt($current_planet[$resource[24]]));

        $MaxMetalStorage = self::num($current_planet['planet_metal_max']);
        $MaxCristalStorage = self::num($current_planet['planet_crystal_max']);
        $MaxDeuteriumStorage = self::num($current_planet['planet_deuterium_max']);

        $Caps = [];
        $BuildTemp = $current_planet['planet_temp_max'];
        $sub_query = '';

        $post_percent = $productionService->maxProductionPercentage(
            self::numInt($current_planet['planet_energy_max']),
            self::numInt($current_planet['planet_energy_used'])
        );

        $Caps['planet_metal_perhour'] = 0;
        $Caps['planet_crystal_perhour'] = 0;
        $Caps['planet_deuterium_perhour'] = 0;
        $Caps['planet_energy_max'] = 0;
        $Caps['planet_energy_used'] = 0;

        foreach ($ProdGrid as $ProdID => $formula) {
            $BuildLevelFactor = $current_planet['planet_' . $resource[$ProdID] . '_percent'];
            $BuildLevel = $current_planet[$resource[$ProdID]];
            $BuildEnergy = $current_user['research_energy_technology'];

            // BOOST
            $geologe_boost = 1 + (1 * ($officerService->isOfficerActive(
                self::numInt($current_user['premium_officier_geologist']),
                time()
            ) ? GEOLOGUE : 0));
            $engineer_boost = 1 + (1 * ($officerService->isOfficerActive(
                self::numInt($current_user['premium_officier_engineer']),
                time()
            ) ? ENGINEER_ENERGY : 0));

            // PRODUCTION FORMULAS
            $metal_prod = ($formula['formule']['metal'])($BuildLevel, $BuildLevelFactor, $BuildTemp, $BuildEnergy);
            $crystal_prod = ($formula['formule']['crystal'])($BuildLevel, $BuildLevelFactor, $BuildTemp, $BuildEnergy);
            $deuterium_prod = ($formula['formule']['deuterium'])($BuildLevel, $BuildLevelFactor, $BuildTemp, $BuildEnergy);
            $energy_prod = ($formula['formule']['energy'])($BuildLevel, $BuildLevelFactor, $BuildTemp, $BuildEnergy);

            // PLASMA BOOST
            $metalBoost = Formulas::getPlasmaTechnologyBonus(self::numInt($current_user['research_plasma_technology']), 'metal');
            $crystalBoost = Formulas::getPlasmaTechnologyBonus(self::numInt($current_user['research_plasma_technology']), 'crystal');
            $deuteriumBoost = Formulas::getPlasmaTechnologyBonus(self::numInt($current_user['research_plasma_technology']), 'deuterium');

            // PRODUCTION BOOST WITH OFFICERS
            $Caps['planet_metal_perhour'] += $productionService->currentProduction(
                $productionService->productionAmount($metal_prod, $geologe_boost, $game_resource_multiplier),
                $post_percent
            );

            $Caps['planet_crystal_perhour'] += $productionService->currentProduction(
                $productionService->productionAmount($crystal_prod, $geologe_boost, $game_resource_multiplier),
                $post_percent
            );

            $Caps['planet_deuterium_perhour'] += $productionService->currentProduction(
                $productionService->productionAmount($deuterium_prod, $geologe_boost, $game_resource_multiplier),
                $post_percent
            );

            // PRODUCTION BOOST WITH PLASMA
            $Caps['planet_metal_perhour'] += $productionService->currentProduction(
                $productionService->productionAmount($metal_prod, $metalBoost, $game_resource_multiplier),
                $post_percent
            );

            $Caps['planet_crystal_perhour'] += $productionService->currentProduction(
                $productionService->productionAmount($crystal_prod, $crystalBoost, $game_resource_multiplier),
                $post_percent
            );

            $Caps['planet_deuterium_perhour'] += $productionService->currentProduction(
                $productionService->productionAmount($deuterium_prod, $deuteriumBoost, $game_resource_multiplier),
                $post_percent
            );

            if ($ProdID >= 4) {
                if ($ProdID == 12 && $current_planet['planet_deuterium'] == 0) {
                    continue;
                }

                $Caps['planet_energy_max'] += $productionService->productionAmount(
                    $energy_prod,
                    $engineer_boost,
                    0,
                    true
                );
            } else {
                $Caps['planet_energy_used'] += $productionService->productionAmount(
                    $energy_prod,
                    1,
                    0,
                    true
                );
            }
        }

        if ($current_planet['planet_type'] == PlanetTypesEnumerator::MOON) {
            $game_metal_basic_income = 0;
            $game_crystal_basic_income = 0;
            $game_deuterium_basic_income = 0;
            $current_planet['planet_metal_perhour'] = 0;
            $current_planet['planet_crystal_perhour'] = 0;
            $current_planet['planet_deuterium_perhour'] = 0;
            $current_planet['planet_energy_used'] = 0;
            $current_planet['planet_energy_max'] = 0;
        } else {
            $current_planet['planet_metal_perhour'] = $Caps['planet_metal_perhour'] + $game_metal_basic_income;
            $current_planet['planet_crystal_perhour'] = $Caps['planet_crystal_perhour'] + $game_crystal_basic_income;
            $current_planet['planet_deuterium_perhour'] = $Caps['planet_deuterium_perhour'] + $game_deuterium_basic_income;
            $current_planet['planet_energy_used'] = $Caps['planet_energy_used'];
            $current_planet['planet_energy_max'] = $Caps['planet_energy_max'];
        }

        $ProductionTime = $UpdateTime - self::numInt($current_planet['planet_last_update']);
        $current_planet['planet_last_update'] = $UpdateTime;

        if ($current_planet['planet_energy_max'] == 0) {
            $current_planet['planet_metal_perhour'] = $game_metal_basic_income;
            $current_planet['planet_crystal_perhour'] = $game_crystal_basic_income;
            $current_planet['planet_deuterium_perhour'] = $game_deuterium_basic_income;

            $production_level = 100;
        } elseif ($current_planet['planet_energy_max'] >= $current_planet['planet_energy_used']) {
            $production_level = 100;
        } else {
            $production_level = floor(
                ((float) $current_planet['planet_energy_max'] / (float) $current_planet['planet_energy_used']) * 100
            );
        }

        if ($production_level > 100) {
            $production_level = 100;
        } elseif ($production_level < 0) {
            $production_level = 0;
        }

        if ($current_planet['planet_metal'] <= $MaxMetalStorage) {
            $MetalProduction = (
                ($ProductionTime * ($current_planet['planet_metal_perhour'] / 3600))
            ) * (0.01 * $production_level);

            $MetalBaseProduc = (($ProductionTime * ($game_metal_basic_income / 3600)));
            $MetalTheorical = self::num($current_planet['planet_metal']) + $MetalProduction + $MetalBaseProduc;

            if ($MetalTheorical <= $MaxMetalStorage) {
                $current_planet['planet_metal'] = $MetalTheorical;
            } else {
                $current_planet['planet_metal'] = $MaxMetalStorage;
            }
        }

        if ($current_planet['planet_crystal'] <= $MaxCristalStorage) {
            $CristalProduction = (
                ($ProductionTime * ($current_planet['planet_crystal_perhour'] / 3600))
            ) * (0.01 * $production_level);

            $CristalBaseProduc = (($ProductionTime * ($game_crystal_basic_income / 3600)));
            $CristalTheorical = self::num($current_planet['planet_crystal']) + $CristalProduction + $CristalBaseProduc;

            if ($CristalTheorical <= $MaxCristalStorage) {
                $current_planet['planet_crystal'] = $CristalTheorical;
            } else {
                $current_planet['planet_crystal'] = $MaxCristalStorage;
            }
        }

        if ($current_planet['planet_deuterium'] <= $MaxDeuteriumStorage) {
            $DeuteriumProduction = (
                ($ProductionTime * ($current_planet['planet_deuterium_perhour'] / 3600))
            ) * (0.01 * $production_level);

            $DeuteriumBaseProduc = (($ProductionTime * ($game_deuterium_basic_income / 3600)));
            $DeuteriumTheorical = self::num($current_planet['planet_deuterium']) +
                $DeuteriumProduction + $DeuteriumBaseProduc;

            if ($DeuteriumTheorical <= $MaxDeuteriumStorage) {
                $current_planet['planet_deuterium'] = $DeuteriumTheorical;
            } else {
                $current_planet['planet_deuterium'] = $MaxDeuteriumStorage;
            }
        }

        if ($current_planet['planet_metal'] < 0) {
            $current_planet['planet_metal'] = 0;
        }

        if ($current_planet['planet_crystal'] < 0) {
            $current_planet['planet_crystal'] = 0;
        }

        if ($current_planet['planet_deuterium'] < 0) {
            $current_planet['planet_deuterium'] = 0;
        }

        if ($Simul == false) {
            // SHIPS AND DEFENSES UPDATE
            $builded = self::updateHangarQueue($current_user, $current_planet, $ProductionTime);
            $ship_points = 0;
            $defense_points = 0;

            if ($builded != '') {
                foreach ($builded as $element => $count) {
                    if ($element != '') {
                        // POINTS
                        switch ($element) {
                            case (($element >= 202) && ($element <= 215)):
                                $ship_points += StatisticsLibrary::calculatePoints($element, $count) * $count;
                                break;
                            case (($element >= 401) && ($element <= 503)):
                                $defense_points += StatisticsLibrary::calculatePoints($element, $count) * $count;
                                break;
                            default:
                                break;
                        }

                        if ($resource[$element] != '') {
                            $sub_query .= '`' . self::str($resource[$element]) . "` = '" . self::str($current_planet[$resource[$element]]) . "', ";
                        }
                    }
                }
            }

            $tech_query = '';

            $data = [
                'planet' => $current_planet,
                'ship_points' => $ship_points,
                'defense_points' => $defense_points,
                'sub_query' => $sub_query,
                'tech_query' => $tech_query,
            ];

            DB::statement(
                self::sql(
                    'UPDATE ' . PLANETS . ' AS p
                    INNER JOIN ' . USERS_STATISTICS . ' AS us ON us.user_statistic_user_id = p.planet_user_id
                    INNER JOIN ' . DEFENSES . ' AS d ON d.defense_planet_id = p.`planet_id`
                    INNER JOIN ' . SHIPS . ' AS s ON s.ship_planet_id = p.`planet_id`
                    INNER JOIN ' . RESEARCH . " AS r ON r.research_user_id = p.planet_user_id SET
                        `planet_metal` = '" . self::str($data['planet']['planet_metal']) . "',
                        `planet_crystal` = '" . self::str($data['planet']['planet_crystal']) . "',
                        `planet_deuterium` = '" . self::str($data['planet']['planet_deuterium']) . "',
                        `planet_last_update` = '" . self::str($data['planet']['planet_last_update']) . "',
                        `planet_b_hangar_id` = '" . self::str($data['planet']['planet_b_hangar_id']) . "',
                        `planet_metal_perhour` = '" . self::str($data['planet']['planet_metal_perhour']) . "',
                        `planet_crystal_perhour` = '" . self::str($data['planet']['planet_crystal_perhour']) . "',
                        `planet_deuterium_perhour` = '" . self::str($data['planet']['planet_deuterium_perhour']) . "',
                        `planet_energy_used` = '" . self::str($data['planet']['planet_energy_used']) . "',
                        `planet_energy_max` = '" . self::str($data['planet']['planet_energy_max']) . "',
                        `user_statistic_ships_points` = `user_statistic_ships_points` + '" . $data['ship_points'] . "',
                        `user_statistic_defenses_points` = `user_statistic_defenses_points`  + '" . $data['defense_points'] . "',
                        `user_statistic_military_points` = `user_statistic_military_points` + '" . ($data['ship_points'] + $data['defense_points']) . "',
                        {$data['sub_query']}
                        {$data['tech_query']}
                        `planet_b_hangar` = '" . self::str($data['planet']['planet_b_hangar']) . "'
                    WHERE `planet_id` = '" . self::str($data['planet']['planet_id']) . "';"
                )
            );
        }
    }

    /**
     * Update the hangar queue, ships and defenses that were on queue.
     *
     * @param array<string, mixed> $current_user
     * @param array<string, mixed> $current_planet
     *
     * @return array<int|string, int>
     *
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     * @SuppressWarnings("PHPMD.ElseExpression")
     */
    private static function updateHangarQueue(array $current_user, array &$current_planet, int $ProductionTime): array
    {
        $resource = Objects::getInstance()->getObjects();

        if (self::str($current_planet['planet_b_hangar_id']) !== '') {
            $Builded = [];
            $BuildArray = [];
            $BuildQueue = explode(';', self::str($current_planet['planet_b_hangar_id']));

            // Work on a local numeric copy of the hangar timer and queue string,
            // then sync both back once (the by-ref array holds mixed values).
            $hangar = self::num($current_planet['planet_b_hangar']) + $ProductionTime;

            foreach ($BuildQueue as $Node => $Array) {
                if ($Array === '') {
                    continue;
                }

                $Item = explode(',', $Array);

                if ($Item[0] != 0) {
                    $AcumTime = Developments::developmentTime(
                        $current_user,
                        $current_planet,
                        self::numInt($Item[0])
                    );
                    $BuildArray[$Node] = [$Item[0], $Item[1] ?? 0, $AcumTime];
                }
            }

            $hangarId = '';
            $UnFinished = false;

            foreach ($BuildArray as $Item) {
                $Element = self::str($Item[0]);
                $Count = self::num($Item[1]);
                $BuildTime = self::num($Item[2]);
                $resourceKey = self::str($resource[$Element] ?? '');
                $Builded[$Element] = 0;

                if (!$UnFinished && $BuildTime > 0) {
                    if ($hangar >= $BuildTime) {
                        $Done = min($Count, floor($hangar / $BuildTime));

                        if ($Count > $Done) {
                            $hangar -= $BuildTime * $Done;
                            $UnFinished = true;
                            $Count -= $Done;
                        } else {
                            $hangar -= $BuildTime * $Count;
                            $Count = 0;
                        }

                        $Builded[$Element] += (int) $Done;
                        $current_planet[$resourceKey] = self::num($current_planet[$resourceKey] ?? 0) + $Done;
                    } else {
                        $UnFinished = true;
                    }
                } elseif (!$UnFinished) {
                    $Builded[$Element] += (int) $Count;
                    $current_planet[$resourceKey] = self::num($current_planet[$resourceKey] ?? 0) + $Count;
                    $Count = 0;
                }

                if ($Count != 0) {
                    $hangarId .= $Element . ',' . $Count . ';';
                }
            }

            $current_planet['planet_b_hangar'] = $hangar;
            $current_planet['planet_b_hangar_id'] = $hangarId;
        } else {
            $Builded = [];
            $current_planet['planet_b_hangar'] = 0;
        }

        return $Builded;
    }

    /**
     * Coerces an untyped game-state value to a float exactly as the legacy
     * string arithmetic did implicitly, but without the type errors/notices.
     */
    private static function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function numInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
