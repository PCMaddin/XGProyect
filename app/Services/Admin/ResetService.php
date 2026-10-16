<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use App\Libraries\PlanetLib;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class ResetService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function resetDefenses(): void
    {
        DB::table('defenses')->update([
            'defense_rocket_launcher' => 0,
            'defense_light_laser' => 0,
            'defense_heavy_laser' => 0,
            'defense_gauss_cannon' => 0,
            'defense_ion_cannon' => 0,
            'defense_plasma_turret' => 0,
            'defense_small_shield_dome' => 0,
            'defense_large_shield_dome' => 0,
            'defense_anti-ballistic_missile' => 0,
            'defense_interplanetary_missile' => 0,
        ]);
    }

    public function resetShips(): void
    {
        DB::table('ships')->update([
            'ship_small_cargo_ship' => 0,
            'ship_big_cargo_ship' => 0,
            'ship_light_fighter' => 0,
            'ship_heavy_fighter' => 0,
            'ship_cruiser' => 0,
            'ship_battleship' => 0,
            'ship_colony_ship' => 0,
            'ship_recycler' => 0,
            'ship_espionage_probe' => 0,
            'ship_bomber' => 0,
            'ship_solar_satellite' => 0,
            'ship_destroyer' => 0,
            'ship_deathstar' => 0,
            'ship_reaper' => 0,
        ]);
    }

    public function resetShipyardQueues(): void
    {
        DB::table('planets')->update([
            'planet_b_hangar' => 0,
            'planet_b_hangar_id' => '',
        ]);
    }

    public function resetPlanetBuildings(): void
    {
        $this->resetBuildingsByType(1);
    }

    public function resetMoonBuildings(): void
    {
        $this->resetBuildingsByType(3);
    }

    /**
     * Bugfix: the queues live in building_queues since the queue tables;
     * clearing only the planets' cache columns left them running.
     */
    public function resetBuildingsQueues(): void
    {
        DB::table('building_queues')->delete();
        DB::table('planets')->update([
            'planet_b_building' => 0,
            'planet_b_building_id' => '',
        ]);
    }

    public function resetResearch(): void
    {
        DB::table('research')->update([
            'research_espionage_technology' => 0,
            'research_computer_technology' => 0,
            'research_weapons_technology' => 0,
            'research_shielding_technology' => 0,
            'research_armour_technology' => 0,
            'research_energy_technology' => 0,
            'research_hyperspace_technology' => 0,
            'research_combustion_drive' => 0,
            'research_impulse_drive' => 0,
            'research_hyperspace_drive' => 0,
            'research_laser_technology' => 0,
            'research_ionic_technology' => 0,
            'research_plasma_technology' => 0,
            'research_intergalactic_research_network' => 0,
            'research_astrophysics' => 0,
            'research_graviton_technology' => 0,
        ]);
    }

    /**
     * Bugfix: the queue lives in research_queues (see resetBuildingsQueues).
     */
    public function resetResearchQueues(): void
    {
        DB::table('research_queues')->delete();
        DB::table('planets')->update([
            'planet_b_tech' => 0,
            'planet_b_tech_id' => 0,
        ]);

        DB::table('research')->update([
            'research_current_research' => 0,
        ]);
    }

    public function resetOfficiers(): void
    {
        DB::table('premium')->update([
            'premium_officier_commander' => 0,
            'premium_officier_admiral' => 0,
            'premium_officier_engineer' => 0,
            'premium_officier_geologist' => 0,
            'premium_officier_technocrat' => 0,
        ]);
    }

    public function resetDarkMatter(): void
    {
        DB::table('premium')->update(['premium_dark_matter' => 0]);
    }

    public function resetResources(): void
    {
        DB::table('planets')->update([
            'planet_metal' => 0,
            'planet_crystal' => 0,
            'planet_deuterium' => 0,
        ]);
    }

    public function resetNotes(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('notes')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function resetReports(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('reports')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function resetFriends(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('buddys')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function resetAlliances(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('alliance')->truncate();
        DB::table('alliance_statistics')->truncate();
        DB::table('users')->update([
            'ally_id' => 0,
            'ally_request' => 0,
            'ally_request_text' => null,
            'ally_register_time' => 0,
            'ally_rank_id' => 0,
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function resetFleets(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('acs')->truncate();
        DB::table('acs_members')->truncate();
        DB::table('fleets')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function resetBanned(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('bans')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function resetMessages(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('messages')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function resetStatistics(): void
    {
        DB::table('users_statistics')->update([
            'user_statistic_buildings_old_rank' => 0,
            'user_statistic_buildings_rank' => 0,
            'user_statistic_defenses_old_rank' => 0,
            'user_statistic_defenses_rank' => 0,
            'user_statistic_ships_old_rank' => 0,
            'user_statistic_ships_rank' => 0,
            'user_statistic_military_old_rank' => 0,
            'user_statistic_military_rank' => 0,
            'user_statistic_technology_old_rank' => 0,
            'user_statistic_technology_rank' => 0,
            'user_statistic_total_old_rank' => 0,
            'user_statistic_total_rank' => 0,
            'user_statistic_update_time' => 0,
            'user_statistic_buildings_points' => 0,
            'user_statistic_defenses_points' => 0,
            'user_statistic_ships_points' => 0,
            'user_statistic_military_points' => 0,
            'user_statistic_technology_points' => 0,
            'user_statistic_total_points' => 0,
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('alliance_statistics')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function resetMoons(): void
    {
        DB::table('planets')->where('planet_type', 3)->delete();
    }

    /**
     * Empty the tables a full reset starts over with. Use DELETE (DML)
     * instead of TRUNCATE (DDL) so the whole reset can be rolled back; with
     * the foreign key checks off nothing cascades, so the queues and items
     * are listed too.
     */
    private function clearGameTables(): void
    {
        $tables = [
            'alliance', 'alliance_statistics', 'acs', 'acs_members', 'fleets', 'buddys', 'messages', 'notes', 'reports',
            'building_queues', 'research_queues', 'planet_boosters', 'user_items', 'import_exports', 'auction_bids', 'auctions',
            'planets', 'buildings', 'defenses', 'ships', 'preferences', 'premium', 'research', 'sessions',
        ];

        foreach ($tables as $table) {
            DB::table($table)->delete();
        }
    }

    public function resetAll(): void
    {
        // FK checks must live outside the transaction — SET is a session variable,
        // not rolled back by MySQL on transaction rollback.
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        try {
            DB::transaction(function () {
                $this->clearGameTables();

                DB::table('users_statistics')->update([
                    'user_statistic_buildings_old_rank' => 0,
                    'user_statistic_buildings_rank' => 0,
                    'user_statistic_defenses_old_rank' => 0,
                    'user_statistic_defenses_rank' => 0,
                    'user_statistic_ships_old_rank' => 0,
                    'user_statistic_ships_rank' => 0,
                    'user_statistic_military_old_rank' => 0,
                    'user_statistic_military_rank' => 0,
                    'user_statistic_technology_old_rank' => 0,
                    'user_statistic_technology_rank' => 0,
                    'user_statistic_total_old_rank' => 0,
                    'user_statistic_total_rank' => 0,
                    'user_statistic_update_time' => 0,
                    'user_statistic_buildings_points' => 0,
                    'user_statistic_defenses_points' => 0,
                    'user_statistic_ships_points' => 0,
                    'user_statistic_military_points' => 0,
                    'user_statistic_technology_points' => 0,
                    'user_statistic_total_points' => 0,
                ]);

                DB::table('users')->update([
                    'lastip' => '',
                    'ip_at_reg' => '',
                    'agent' => '',
                    'current_page' => '',
                    'fleet_shortcuts' => '',
                    'ally_id' => 0,
                    'ally_request' => 0,
                    'ally_request_text' => null,
                    'ally_register_time' => 0,
                    'ally_rank_id' => 0,
                    'onlinetime' => time(),
                ]);

                $creator = new PlanetLib();

                foreach (User::all() as $user) {
                    DB::table('research')->insert(['research_user_id' => $user->id]);

                    DB::table('premium')->insert([
                        'premium_user_id' => $user->id,
                        'premium_dark_matter' => $this->settings->getInt('registration_dark_matter'),
                    ]);

                    DB::table('preferences')->insert(['preference_user_id' => $user->id]);

                    $creator->setNewPlanet(
                        (int) $user->galaxy,
                        (int) $user->system,
                        (int) $user->planet,
                        $user->id,
                        '',
                        true
                    );

                    $lastPlanetId = DB::getPdo()->lastInsertId();

                    DB::table('users')->where('id', $user->id)->update([
                        'home_planet_id' => $lastPlanetId,
                        'current_planet' => $lastPlanetId,
                    ]);
                }
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    private function resetBuildingsByType(int $planetType): void
    {
        DB::table('buildings')
            ->join('planets', 'buildings.building_planet_id', '=', 'planets.planet_id')
            ->where('planets.planet_type', $planetType)
            ->update([
                'buildings.building_metal_mine' => 0,
                'buildings.building_crystal_mine' => 0,
                'buildings.building_deuterium_sintetizer' => 0,
                'buildings.building_solar_plant' => 0,
                'buildings.building_fusion_reactor' => 0,
                'buildings.building_robot_factory' => 0,
                'buildings.building_nano_factory' => 0,
                'buildings.building_hangar' => 0,
                'buildings.building_metal_store' => 0,
                'buildings.building_crystal_store' => 0,
                'buildings.building_deuterium_tank' => 0,
                'buildings.building_laboratory' => 0,
                'buildings.building_terraformer' => 0,
                'buildings.building_ally_deposit' => 0,
                'buildings.building_missile_silo' => 0,
                'buildings.building_mondbasis' => 0,
                'buildings.building_phalanx' => 0,
                'buildings.building_jump_gate' => 0,
            ]);
    }
}
