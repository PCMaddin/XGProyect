<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\MissionControlLib;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\DatabaseTestCase;

/**
 * The fleets the tick processes: each once, with its planet names, even when
 * several planets share the coordinates (a destroyed one and a new colony).
 */
#[CoversClass(MissionControlLib::class)]
class MissionControlFleetsTest extends DatabaseTestCase
{
    public function testEveryArrivingFleetComesOnceWithItsPlanetNames(): void
    {
        DB::table('research')->insert(['research_user_id' => 1, 'research_hyperspace_technology' => 4]);
        DB::table('planets')->insert([
            ['planet_id' => 1, 'planet_name' => 'Home', 'planet_galaxy' => 1, 'planet_system' => 2, 'planet_planet' => 3, 'planet_type' => 1, 'planet_destroyed' => 0],
            ['planet_id' => 2, 'planet_name' => 'Ruin', 'planet_galaxy' => 1, 'planet_system' => 5, 'planet_planet' => 6, 'planet_type' => 1, 'planet_destroyed' => 1700000000],
            ['planet_id' => 3, 'planet_name' => 'New colony', 'planet_galaxy' => 1, 'planet_system' => 5, 'planet_planet' => 6, 'planet_type' => 1, 'planet_destroyed' => 0],
        ]);
        DB::table('fleets')->insert([
            ['fleet_id' => 1, 'fleet_owner' => 1, 'fleet_start_time' => time() - 10, 'fleet_mess' => 0] + self::route([1, 2, 3], [1, 5, 6]),
            ['fleet_id' => 2, 'fleet_owner' => 1, 'fleet_start_time' => time() + 999, 'fleet_mess' => 0] + self::route([1, 2, 3], [1, 5, 6]),
            ['fleet_id' => 3, 'fleet_owner' => 2, 'fleet_start_time' => time() - 5, 'fleet_mess' => 0] + self::route([1, 2, 3], [9, 9, 9]),
        ]);

        $fleets = (new ReflectionMethod(MissionControlLib::class, 'getArrivingFleets'))->invoke(new MissionControlLib());
        $this->assertIsArray($fleets);

        $found = [];

        foreach ($fleets as $fleet) {
            $this->assertIsArray($fleet);
            $found[] = [
                $fleet['fleet_id'] ?? null,
                $fleet['planet_start_name'] ?? null,
                $fleet['planet_end_name'] ?? null,
                $fleet['research_hyperspace_technology'] ?? null,
            ];
        }

        $this->assertEquals([[1, 'Home', 'New colony', 4], [3, 'Home', null, null]], $found);
    }

    /**
     * @param array{int, int, int} $start
     * @param array{int, int, int} $end
     *
     * @return array<string, int>
     */
    private static function route(array $start, array $end): array
    {
        return [
            'fleet_start_galaxy' => $start[0], 'fleet_start_system' => $start[1], 'fleet_start_planet' => $start[2], 'fleet_start_type' => 1,
            'fleet_end_galaxy' => $end[0], 'fleet_end_system' => $end[1], 'fleet_end_planet' => $end[2], 'fleet_end_type' => 1,
        ];
    }
}
