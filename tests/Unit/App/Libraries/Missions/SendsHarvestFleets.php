<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Support\FleetShips;
use Illuminate\Support\Facades\DB;

/**
 * A recycling fleet of player 5 from 1:1:1 to 1:2:$position.
 */
trait SendsHarvestFleets
{
    /**
     * @param array<int, int> $ships
     */
    private function fleet(array $ships, int $position): int
    {
        DB::table('users')->insert(['id' => 5, 'name' => 'Harvester', 'email' => 'h@example.com', 'password' => 'x', 'home_planet_id' => 1, 'current_planet' => 1]);

        return DB::table('fleets')->insertGetId([
            'fleet_owner' => 5, 'fleet_mission' => 8, 'fleet_mess' => 0, 'fleet_array' => FleetShips::encode($ships), 'fleet_amount' => array_sum($ships),
            'fleet_start_time' => time() - 10, 'fleet_end_time' => time() + 600,
            'fleet_start_galaxy' => 1, 'fleet_start_system' => 1, 'fleet_start_planet' => 1, 'fleet_start_type' => 1,
            'fleet_end_galaxy' => 1, 'fleet_end_system' => 2, 'fleet_end_planet' => $position, 'fleet_end_type' => 2,
        ], 'fleet_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $fleetId): array
    {
        return (array) DB::table('fleets')->where('fleet_id', $fleetId)->first() + ['research_hyperspace_technology' => 0, 'planet_start_name' => 'Home', 'planet_end_name' => ''];
    }
}
