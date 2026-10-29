<?php

declare(strict_types=1);

namespace App\Services\Operators;

use App\Core\Enumerators\MissionsEnumerator;
use App\Core\Enumerators\ShipsEnumerator;
use App\Support\FleetShips;
use Illuminate\Support\Facades\DB;

/**
 * Builds up the load-test players in bulk, so the pages have something to
 * show: buildings, research, ships and defences in three stages, messages,
 * alliances of about 50, friends, and probes in flight.
 */
class LoadTestWorld
{
    private const MESSAGES_EACH = 20;
    private const ALLIANCE_SIZE = 50;
    private const INSERT_ROWS = 1000;

    /** three stages of development, by player number */
    private const BUILDINGS = [
        ['building_metal_mine' => 12, 'building_crystal_mine' => 10, 'building_deuterium_sintetizer' => 8, 'building_solar_plant' => 12, 'building_robot_factory' => 4, 'building_hangar' => 4, 'building_laboratory' => 5],
        ['building_metal_mine' => 20, 'building_crystal_mine' => 17, 'building_deuterium_sintetizer' => 14, 'building_solar_plant' => 20, 'building_robot_factory' => 10, 'building_nano_factory' => 2, 'building_hangar' => 8, 'building_laboratory' => 10],
        ['building_metal_mine' => 28, 'building_crystal_mine' => 24, 'building_deuterium_sintetizer' => 20, 'building_solar_plant' => 26, 'building_fusion_reactor' => 10, 'building_robot_factory' => 10, 'building_nano_factory' => 5, 'building_hangar' => 12, 'building_laboratory' => 12],
    ];
    private const RESEARCH = [
        ['research_energy_technology' => 3, 'research_combustion_drive' => 4, 'research_espionage_technology' => 2, 'research_computer_technology' => 2],
        ['research_energy_technology' => 8, 'research_combustion_drive' => 6, 'research_impulse_drive' => 5, 'research_espionage_technology' => 6, 'research_computer_technology' => 8, 'research_weapons_technology' => 6, 'research_shielding_technology' => 5, 'research_armour_technology' => 6],
        ['research_energy_technology' => 12, 'research_combustion_drive' => 10, 'research_impulse_drive' => 8, 'research_hyperspace_drive' => 6, 'research_hyperspace_technology' => 8, 'research_espionage_technology' => 10, 'research_computer_technology' => 12, 'research_weapons_technology' => 12, 'research_shielding_technology' => 10, 'research_armour_technology' => 12, 'research_astrophysics' => 9],
    ];
    private const SHIPS = [
        ['ship_small_cargo_ship' => 10, 'ship_espionage_probe' => 5],
        ['ship_small_cargo_ship' => 40, 'ship_big_cargo_ship' => 10, 'ship_light_fighter' => 200, 'ship_cruiser' => 20, 'ship_espionage_probe' => 20, 'ship_recycler' => 10],
        ['ship_small_cargo_ship' => 100, 'ship_big_cargo_ship' => 60, 'ship_light_fighter' => 1000, 'ship_cruiser' => 150, 'ship_battleship' => 100, 'ship_espionage_probe' => 50, 'ship_recycler' => 40],
    ];
    private const DEFENSES = [
        ['defense_rocket_launcher' => 20],
        ['defense_rocket_launcher' => 200, 'defense_light_laser' => 100, 'defense_heavy_laser' => 10],
        ['defense_rocket_launcher' => 1000, 'defense_light_laser' => 500, 'defense_heavy_laser' => 100, 'defense_gauss_cannon' => 20, 'defense_small_shield_dome' => 1],
    ];

    /**
     * @param list<int> $ids a few hundred players at a time
     */
    public function develop(array $ids, int $now): void
    {
        $homes = $this->homes($ids);

        foreach ($homes as $index => $home) {
            $stage = $index % 3;
            DB::table('buildings')->where('building_planet_id', $home['planet'])->update(self::BUILDINGS[$stage]);
            DB::table('research')->where('research_user_id', $home['id'])->update(self::RESEARCH[$stage]);
            DB::table('ships')->where('ship_planet_id', $home['planet'])->update(self::SHIPS[$stage]);
            DB::table('defenses')->where('defense_planet_id', $home['planet'])->update(self::DEFENSES[$stage]);
            DB::table('planets')->where('planet_id', $home['planet'])->update([
                'planet_metal' => 50000 * ($stage + 1), 'planet_crystal' => 30000 * ($stage + 1), 'planet_deuterium' => 10000 * ($stage + 1), 'planet_last_update' => $now,
            ]);
        }

        if (count($homes) < 2) {
            return;
        }

        $this->insert('messages', $this->messages($homes, $now));
        $this->alliances($homes, $now);
        $this->insert('buddys', $this->friends($homes));
        $this->insert('fleets', $this->fleets($homes, $now));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array{id: int, planet: int, coords: array{int, int, int}}>
     */
    private function homes(array $ids): array
    {
        $rows = DB::table('users')
            ->join('planets', 'planets.planet_id', '=', 'users.home_planet_id')
            ->whereIn('users.id', $ids)
            ->orderBy('users.id')
            ->get(['users.id', 'users.home_planet_id', 'planets.planet_galaxy', 'planets.planet_system', 'planets.planet_planet']);
        $homes = [];

        foreach ($rows as $row) {
            $homes[] = [
                'id' => self::int($row->id ?? 0), 'planet' => self::int($row->home_planet_id ?? 0),
                'coords' => [self::int($row->planet_galaxy ?? 0), self::int($row->planet_system ?? 0), self::int($row->planet_planet ?? 0)],
            ];
        }

        return $homes;
    }

    /**
     * Every second one from the next player, the others from the fleet
     * command; the newest three unread.
     *
     * @param list<array{id: int, planet: int, coords: array{int, int, int}}> $homes
     *
     * @return list<array<string, int|string>>
     */
    private function messages(array $homes, int $now): array
    {
        $rows = [];
        $count = count($homes);

        foreach ($homes as $index => $home) {
            $sender = $homes[($index + 1) % $count]['id'];

            for ($i = 0; $i < self::MESSAGES_EACH; $i++) {
                $fromPlayer = $i % 2 === 0;
                $rows[] = [
                    'message_sender' => $fromPlayer ? $sender : 0,
                    'message_receiver' => $home['id'],
                    'message_time' => $now - $i * 3600,
                    'message_type' => $fromPlayer ? 1 : 0,
                    'message_from' => $fromPlayer ? 'Load test player' : 'Fleet command',
                    'message_subject' => 'Load test ' . $i,
                    'message_text' => str_repeat('A message of the load test. ', 10),
                    'message_read' => $i < 3 ? 0 : 1,
                ];
            }
        }

        return $rows;
    }

    /**
     * Every third player in an alliance of about 50.
     *
     * @param list<array{id: int, planet: int, coords: array{int, int, int}}> $homes
     */
    private function alliances(array $homes, int $now): void
    {
        $members = array_values(array_filter($homes, static fn (array $home): bool => $home['id'] % 3 === 0));

        foreach (array_chunk($members, self::ALLIANCE_SIZE) as $group) {
            $founder = $group[0]['id'];
            $alliance = DB::table('alliance')->insertGetId([
                'alliance_name' => 'Load test ' . $founder, 'alliance_tag' => 'LT' . $founder % 100000, 'alliance_owner' => $founder,
                'alliance_register_time' => $now, 'alliance_ranks' => '[]', 'alliance_description' => 'An alliance of the load test.', 'alliance_text' => '', 'alliance_request' => '',
            ]);
            DB::table('alliance_statistics')->insert(['alliance_statistic_alliance_id' => $alliance]);
            DB::table('users')->whereIn('id', array_column($group, 'id'))->update(['ally_id' => $alliance, 'ally_rank_id' => 0, 'ally_register_time' => $now]);
        }
    }

    /**
     * Two by two.
     *
     * @param list<array{id: int, planet: int, coords: array{int, int, int}}> $homes
     *
     * @return list<array<string, int|string>>
     */
    private function friends(array $homes): array
    {
        $rows = [];

        foreach (array_chunk($homes, 2) as $pair) {
            if (count($pair) === 2) {
                $rows[] = ['buddy_sender' => $pair[0]['id'], 'buddy_receiver' => $pair[1]['id'], 'buddy_status' => 1, 'buddy_request_text' => ''];
            }
        }

        return $rows;
    }

    /**
     * Every fifth player has a probe on its way to the next player,
     * arriving within the next eight hours (the tick spies and returns it).
     *
     * @param list<array{id: int, planet: int, coords: array{int, int, int}}> $homes
     *
     * @return list<array<string, int|string>>
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    private function fleets(array $homes, int $now): array
    {
        $rows = [];
        $count = count($homes);
        $probe = FleetShips::encode([ShipsEnumerator::SHIP_ESPIONAGE_PROBE => 1]);

        foreach ($homes as $index => $home) {
            if ($index % 5 !== 0) {
                continue;
            }

            $target = $homes[($index + 1) % $count];
            $arrival = $now + 600 + ($index * 977) % 28800;
            $rows[] = [
                'fleet_owner' => $home['id'], 'fleet_mission' => MissionsEnumerator::SPY, 'fleet_amount' => 1, 'fleet_array' => $probe,
                'fleet_start_time' => $arrival, 'fleet_start_galaxy' => $home['coords'][0], 'fleet_start_system' => $home['coords'][1], 'fleet_start_planet' => $home['coords'][2], 'fleet_start_type' => 1,
                'fleet_end_time' => $arrival + ($arrival - $now), 'fleet_end_galaxy' => $target['coords'][0], 'fleet_end_system' => $target['coords'][1], 'fleet_end_planet' => $target['coords'][2], 'fleet_end_type' => 1,
                'fleet_target_owner' => $target['id'], 'fleet_creation' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, int|string>> $rows
     */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_ROWS) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
