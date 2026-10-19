<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Services\Account\AccountEraser;
use App\Support\OfficerBonuses;
use App\Services\Game\RelocationService;
use App\Services\Game\WreckFieldService;
use App\Services\Game\Formulas\FormulasService;
use App\Services\Game\Formulas\ClassProductionService;
use App\Services\Game\PlayerClassService;
use App\Models\Planets;
use App\Models\User;
use App\Services\Admin\BackupService;
use App\Services\Game\AuctionService;
use App\Services\Game\Bots\BotPlayerService;
use App\Services\Game\BuildingQueueService;
use App\Services\Game\Items\ItemService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\Game\Formulas\ProductionService;
use App\Services\Game\ResearchQueueService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use App\Core\Concerns\PreparesSql;
use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\PlanetTypesEnumerator;
use App\Core\Enumerators\ResearchEnumerator as Research;
use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Game\Formulas\DevelopmentsService;

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
    use PreparesSql;

    /** the option the scheduler's tick (xgp:tick) writes its time to */
    public const HEARTBEAT = 'tick_last_run';

    /** seconds a tick of the scheduler counts as recent */
    public const SCHEDULER_ALIVE = 300;

    /**
     * The tick: for a page view, or for the scheduler's xgp:tick started at
     * $scheduledAt. Fleets, relocations and auctions run on every page view, so
     * a player sees what arrived. Cleanup, backup, statistics and bots are
     * left to the scheduler while it runs; when it stops, page views take
     * them over again.
     */
    public function __construct(?int $scheduledAt = null)
    {
        $settings = app(SettingsService::class);

        if ($scheduledAt !== null) {
            $settings->write(self::HEARTBEAT, $scheduledAt);
        }

        $background = $scheduledAt !== null || !self::schedulerRuns($settings);

        if ($background) {
            $this->cleanUp();
            $this->createBackup();
        }

        $this->updateFleets();
        app(RelocationService::class)->settleDue(time());

        if ($background) {
            $this->updateStatistics();
        }

        app(AuctionService::class)->settleEnded(time());

        if ($background) {
            app(BotPlayerService::class)->playDue(time());
        }
    }

    /**
     * Whether the scheduler ticked this universe in the last minutes.
     */
    public static function schedulerRuns(SettingsService $settings): bool
    {
        $options = $settings->all();

        return isset($options[self::HEARTBEAT]) && time() - (int) $options[self::HEARTBEAT]->value < self::SCHEDULER_ALIVE;
    }

    /**
     * Every six hours: players who asked to be deleted or stayed away for a
     * month, old messages and reports, destroyed planets, empty fleet
     * unions. One page view claims it and does it after its answer.
     */
    private function cleanUp(): void
    {
        $settings = app(SettingsService::class);
        $lastCleanup = $settings->getInt('last_cleanup');
        $cleanupInterval = 6; // 6 HOURS

        if (time() < $lastCleanup + 3600 * $cleanupInterval || !$settings->claim('last_cleanup', $lastCleanup, time())) {
            return;
        }

        $this->afterResponse(fn () => $this->runCleanUp());
    }

    private function runCleanUp(): void
    {
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
                        OR (u.`onlinetime` < '" . $delInactive . "' AND u.`onlinetime` <> 0 AND u.`authlevel` <> 3 AND u.`user_is_bot` = 0)"
                )
            )
        );

        $eraser = app(AccountEraser::class);

        foreach ($chooseToDelete as $delete) {
            $eraser->erase((int) $delete['id']);
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
        // fleet_group is text: compared as text it can use its index
        DB::statement(
            $this->prepareSql(
                'DELETE a,m1,m2 FROM `' . ACS . '` AS a
                INNER JOIN `' . ACS_MEMBERS . '` m1 ON m1.`acs_group_id` = a.`acs_id`
                RIGHT JOIN `' . ACS_MEMBERS . '` m2 ON m2.`acs_group_id` = a.`acs_id`
                LEFT JOIN `' . FLEETS . '` f ON f.`fleet_group` = CAST(a.`acs_id` AS CHAR)
                WHERE f.`fleet_id` IS NULL'
            )
        );
    }

    private function createBackup(): void
    {
        $settings = app(SettingsService::class);
        $autoBackup = $settings->getBool('auto_backup');
        $lastBackup = $settings->getInt('last_backup');
        $updateInterval = 6;

        if (!$autoBackup || time() < $lastBackup + 3600 * $updateInterval || !$settings->claim('last_backup', $lastBackup, time())) {
            return;
        }

        $this->afterResponse(fn () => app(BackupService::class)->createBackup());
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
     * The points and ranks, every stat_update_time minutes. One page view
     * claims the rebuild and runs it after its answer; meanwhile the time
     * stamp lies an hour ahead, so no other view starts it again, not even
     * if this one dies. Before, every page view started it while it was due:
     * with many players it ran longer than the time limit, never finished,
     * and every page failed.
     */
    private function updateStatistics(): void
    {
        $settings = app(SettingsService::class);
        $lastUpdate = $settings->getInt('stat_last_update');
        $now = time();

        if ($now < $lastUpdate + 60 * $settings->getInt('stat_update_time') || !$settings->claim('stat_last_update', $lastUpdate, $now + 3600)) {
            return;
        }

        $this->afterResponse(function () use ($settings): void {
            $settings->write('stat_last_update', (new StatisticsLibrary())->makeStats()['stats_time']);
        });
    }

    /**
     * Work of the tick the player does not wait for: after the page has
     * been sent (php-fpm), without the time limit; on the command line (the
     * scheduler, tests) at once.
     */
    private function afterResponse(\Closure $work): void
    {
        if (app()->runningInConsole()) {
            $work();

            return;
        }

        app()->terminating(static function () use ($work): void {
            ignore_user_abort(true);
            set_time_limit(0);

            try {
                $work();
            } catch (\Throwable $e) {
                report($e);
            }
        });
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
        $registry = app(GameObjectRegistry::class);
        $resource = $registry->names();

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

        // wreck fields burn up, repaired ships go into service on their own
        if (!$Simul) {
            app(WreckFieldService::class)->settle(self::numInt($current_planet['planet_id'] ?? 0), $UpdateTime);
        }

        $storageFactor = PlayerClassService::bonusesFromRow($current_user)->storageFactor();
        $current_planet['planet_metal_max'] = $productionService->maxStorable(self::numInt($current_planet[$resource[22]]), $storageFactor);
        $current_planet['planet_crystal_max'] = $productionService->maxStorable(self::numInt($current_planet[$resource[23]]), $storageFactor);
        $current_planet['planet_deuterium_max'] = $productionService->maxStorable(self::numInt($current_planet[$resource[24]]), $storageFactor);

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

        // the mines' own production, which boosters (items), the
        // collector class and crawlers raise
        $minePerHour = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];
        $energyBase = 0.0;

        foreach ($registry->producers() as $ProdID => $producer) {
            $formula = $producer->getProduction();

            if ($formula === null) {
                continue;
            }

            $BuildLevelFactor = $current_planet['planet_' . $resource[$ProdID] . '_percent'];
            $BuildLevel = $current_planet[$resource[$ProdID]];
            $BuildEnergy = $current_user['research_energy_technology'];

            // BOOST
            // geologist, engineer and the commanding staff
            $officers = OfficerBonuses::fromRow($current_user, time());
            $geologe_boost = 1 + $officers->mineBonus();
            $engineer_boost = 1 + $officers->energyBonus();

            // PRODUCTION FORMULAS
            $level = (int) self::num($BuildLevel);
            $levelFactor = (float) self::num($BuildLevelFactor);
            $temperature = (float) self::num($BuildTemp);
            $metal_prod = $formula->calculateMetal($level, $levelFactor);
            $crystal_prod = $formula->calculateCrystal($level, $levelFactor);
            $deuterium_prod = $formula->calculateDeuterium($level, $levelFactor, $temperature);
            $energy_prod = $formula->calculateEnergy($level, $levelFactor, $temperature, (int) self::num($BuildEnergy));

            // PLASMA BOOST
            $metalBoost = app(FormulasService::class)->getPlasmaTechnologyBonus(self::numInt($current_user['research_plasma_technology']), 'metal');
            $crystalBoost = app(FormulasService::class)->getPlasmaTechnologyBonus(self::numInt($current_user['research_plasma_technology']), 'crystal');
            $deuteriumBoost = app(FormulasService::class)->getPlasmaTechnologyBonus(self::numInt($current_user['research_plasma_technology']), 'deuterium');

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

            // only what the mines produce (not the fusion reactor's use)
            $minePerHour['metal'] += $productionService->currentProduction($productionService->productionAmount(max(0, $metal_prod), 1, $game_resource_multiplier), $post_percent);
            $minePerHour['crystal'] += $productionService->currentProduction($productionService->productionAmount(max(0, $crystal_prod), 1, $game_resource_multiplier), $post_percent);
            $minePerHour['deuterium'] += $productionService->currentProduction($productionService->productionAmount(max(0, $deuterium_prod), 1, $game_resource_multiplier), $post_percent);

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
                $energyBase += $productionService->productionAmount($energy_prod, 1, 0, true);
            } else {
                $Caps['planet_energy_used'] += $productionService->productionAmount(
                    $energy_prod,
                    1,
                    0,
                    true
                );
            }
        }

        // player class and crawlers (OGame 7)
        $extras = app(ClassProductionService::class)->extras(
            $minePerHour,
            $energyBase,
            self::numInt($current_planet['ship_crawler'] ?? 0),
            self::numInt($current_planet['building_metal_mine'] ?? 0) + self::numInt($current_planet['building_crystal_mine'] ?? 0) + self::numInt($current_planet['building_deuterium_sintetizer'] ?? 0),
            self::numInt($current_planet['planet_ship_crawler_percent'] ?? 10),
            PlayerClassService::bonusesFromRow($current_user),
            $officerService->isOfficerActive(self::numInt($current_user['premium_officier_geologist'] ?? 0), time())
        );
        $Caps['planet_metal_perhour'] += $extras['metal'];
        $Caps['planet_crystal_perhour'] += $extras['crystal'];
        $Caps['planet_deuterium_perhour'] += $extras['deuterium'];
        $Caps['planet_energy_max'] += $extras['energyMax'];
        $Caps['planet_energy_used'] -= $extras['energyUsed'];

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

        // what was read: the write adds to it, and only if no other view
        // updated the planet meanwhile (see the end)
        $before = [
            'planet_metal' => self::num($current_planet['planet_metal']),
            'planet_crystal' => self::num($current_planet['planet_crystal']),
            'planet_deuterium' => self::num($current_planet['planet_deuterium']),
            'planet_last_update' => self::numInt($current_planet['planet_last_update']),
            // a shipyard order added meanwhile must not be dropped by this write
            'planet_b_hangar_id' => self::str($current_planet['planet_b_hangar_id'] ?? ''),
        ];
        $ProductionTime = $UpdateTime - $before['planet_last_update'];

        // boosters: the per-hour value shows the ones working now, the
        // resources get each one for the time it worked
        $boosterPerHour = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];
        $boosterProduction = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];

        if ($current_planet['planet_type'] != PlanetTypesEnumerator::MOON && $current_planet['planet_energy_max'] != 0) {
            $items = app(ItemService::class);
            $planetId = self::numInt($current_planet['planet_id']);

            foreach ($items->activeBoosters($planetId, $UpdateTime) as $boosted => $booster) {
                $boosterPerHour[$boosted] = floor($minePerHour[$boosted] * $booster->percent / 100);
                $current_planet['planet_' . $boosted . '_perhour'] = self::num($current_planet['planet_' . $boosted . '_perhour']) + $boosterPerHour[$boosted];
            }

            foreach ($items->boosterShares($planetId, self::numInt($current_planet['planet_last_update']), $UpdateTime) as $boosted => $share) {
                $boosterProduction[$boosted] = $minePerHour[$boosted] * $share * $ProductionTime / 3600;
            }
        }

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
                ($ProductionTime * ((self::num($current_planet['planet_metal_perhour']) - $boosterPerHour['metal']) / 3600))
                + $boosterProduction['metal']
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
                ($ProductionTime * ((self::num($current_planet['planet_crystal_perhour']) - $boosterPerHour['crystal']) / 3600))
                + $boosterProduction['crystal']
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
                ($ProductionTime * ((self::num($current_planet['planet_deuterium_perhour']) - $boosterPerHour['deuterium']) / 3600))
                + $boosterProduction['deuterium']
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
            $builded = self::updateHangarQueue($current_planet, $ProductionTime);
            $ship_points = 0;
            $defense_points = 0;

            if ($builded != '') {
                foreach ($builded as $element => $count) {
                    if ($element != '') {
                        // POINTS
                        switch ($element) {
                            case (($element >= 202) && ($element <= 219)):
                                $ship_points += StatisticsLibrary::calculatePoints($element, $count) * $count;
                                break;
                            case (($element >= 401) && ($element <= 503)):
                                $defense_points += StatisticsLibrary::calculatePoints($element, $count) * $count;
                                break;
                            default:
                                break;
                        }

                        // added, never set: ships that left meanwhile stay gone
                        if ($resource[$element] != '') {
                            $sub_query .= '`' . self::str($resource[$element]) . '` = `' . self::str($resource[$element]) . '` + ' . (int) $count . ', ';
                        }
                    }
                }
            }

            $changed = DB::update(
                self::sql(
                    'UPDATE ' . PLANETS . ' AS p
                    INNER JOIN ' . USERS_STATISTICS . ' AS us ON us.user_statistic_user_id = p.planet_user_id
                    INNER JOIN ' . DEFENSES . ' AS d ON d.defense_planet_id = p.`planet_id`
                    INNER JOIN ' . SHIPS . ' AS s ON s.ship_planet_id = p.`planet_id`
                    INNER JOIN ' . RESEARCH . " AS r ON r.research_user_id = p.planet_user_id SET
                        `planet_metal` = `planet_metal` + ?,
                        `planet_crystal` = `planet_crystal` + ?,
                        `planet_deuterium` = `planet_deuterium` + ?,
                        `planet_last_update` = ?,
                        `planet_b_hangar_id` = ?,
                        `planet_metal_perhour` = ?,
                        `planet_crystal_perhour` = ?,
                        `planet_deuterium_perhour` = ?,
                        `planet_energy_used` = ?,
                        `planet_energy_max` = ?,
                        `user_statistic_ships_points` = `user_statistic_ships_points` + ?,
                        `user_statistic_defenses_points` = `user_statistic_defenses_points` + ?,
                        `user_statistic_military_points` = `user_statistic_military_points` + ?,
                        `user_statistic_military_built_points` = `user_statistic_military_built_points` + ?,
                        {$sub_query}
                        `planet_b_hangar` = ?
                    WHERE `planet_id` = ? AND `planet_last_update` = ? AND `planet_b_hangar_id` = ?;"
                ),
                [
                    self::num($current_planet['planet_metal']) - $before['planet_metal'],
                    self::num($current_planet['planet_crystal']) - $before['planet_crystal'],
                    self::num($current_planet['planet_deuterium']) - $before['planet_deuterium'],
                    self::numInt($current_planet['planet_last_update']),
                    self::str($current_planet['planet_b_hangar_id']),
                    self::str($current_planet['planet_metal_perhour']),
                    self::str($current_planet['planet_crystal_perhour']),
                    self::str($current_planet['planet_deuterium_perhour']),
                    self::str($current_planet['planet_energy_used']),
                    self::str($current_planet['planet_energy_max']),
                    $ship_points,
                    $defense_points,
                    $ship_points + $defense_points,
                    $ship_points + $defense_points,
                    self::str($current_planet['planet_b_hangar']),
                    self::numInt($current_planet['planet_id']),
                    $before['planet_last_update'],
                    $before['planet_b_hangar_id'],
                ]
            );

            // another view got there first: show what is stored, not this view's guess
            if ($changed === 0) {
                self::reload($current_planet, array_keys($builded), $resource);
            }
        }
    }

    /**
     * The planet's stored resources, queue and the built units, after
     * another view updated it first.
     *
     * @param array<string, mixed>     $planet
     * @param array<int, int|string>   $built
     * @param array<int|string, mixed> $names
     *
     * @param-out array<string, mixed> $planet
     */
    private static function reload(array &$planet, array $built, array $names): void
    {
        $columns = ['planet_metal', 'planet_crystal', 'planet_deuterium', 'planet_last_update', 'planet_b_hangar', 'planet_b_hangar_id'];

        foreach ($built as $element) {
            if (is_string($names[$element] ?? null) && $names[$element] !== '') {
                $columns[] = $names[$element];
            }
        }

        $row = DB::table('planets AS p')
            ->join('ships AS s', 's.ship_planet_id', '=', 'p.planet_id')
            ->join('defenses AS d', 'd.defense_planet_id', '=', 'p.planet_id')
            ->where('p.planet_id', self::numInt($planet['planet_id']))
            ->first($columns);

        foreach ((array) $row as $column => $value) {
            $planet[(string) $column] = $value;
        }
    }

    /**
     * Update the hangar queue, ships and defenses that were on queue.
     *
     * @param array<string, mixed> $current_planet
     *
     * @return array<int|string, int>
     *
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     * @SuppressWarnings("PHPMD.ElseExpression")
     */
    private static function updateHangarQueue(array &$current_planet, int $ProductionTime): array
    {
        $resource = app(GameObjectRegistry::class)->names();

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
                    $AcumTime = app(DevelopmentsService::class)->developmentTime(
                        self::numInt($Item[0]),
                        0,
                        self::numInt($current_planet[$resource[Buildings::BUILDING_HANGAR]] ?? 0),
                        self::numInt($current_planet[$resource[Buildings::BUILDING_NANO_FACTORY]] ?? 0),
                        0,
                        0,
                        false
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
