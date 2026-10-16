<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Support\FleetShips;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\MysqlTestCase;

/**
 * The update to the ships of OGame 7: the reapers so far (id 215) keep
 * their column and become id 218 in fleets and shipyard orders; the
 * battlecruiser, crawler and pathfinder get their columns.
 */
#[CoversNothing]
class VersionSevenShipsMysqlTest extends MysqlTestCase
{
    public function testReapersMoveToTheirOwnId(): void
    {
        // the state before the update
        Schema::table('ships', function (Blueprint $table): void {
            $table->dropColumn(['ship_battlecruiser', 'ship_crawler', 'ship_pathfinder']);
        });
        DB::table('planets')->insert(['planet_id' => 1, 'planet_user_id' => 1, 'planet_b_hangar_id' => '215,3;204,10;']);
        DB::table('ships')->insert(['ship_planet_id' => 1, 'ship_reaper' => 7]);
        $fleetId = DB::table('fleets')->insertGetId(['fleet_owner' => 1, 'fleet_array' => FleetShips::encode([215 => 4, 204 => 2])], 'fleet_id');

        $migration = require database_path('migrations/400/2026_10_16_000011_add_version_7_ships.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $this->assertTrue(method_exists($migration, 'up'));
        $migration->up();

        $array = DB::table('fleets')->where('fleet_id', $fleetId)->value('fleet_array');
        $this->assertIsString($array);
        $this->assertSame([204 => 2, 218 => 4], $this->sorted(FleetShips::decode($array)));
        $this->assertSame('218,3;204,10;', DB::table('planets')->value('planet_b_hangar_id'));
        $this->assertSame(['ship_reaper' => 7, 'ship_battlecruiser' => 0, 'ship_crawler' => 0, 'ship_pathfinder' => 0], array_map(
            fn ($value): int => is_numeric($value) ? (int) $value : -1,
            (array) DB::table('ships')->first(['ship_reaper', 'ship_battlecruiser', 'ship_crawler', 'ship_pathfinder'])
        ));

        // a second run changes nothing
        $migration->up();
        $this->assertSame('218,3;204,10;', DB::table('planets')->value('planet_b_hangar_id'));
    }

    /**
     * @param array<int, int> $ships
     *
     * @return array<int, int>
     */
    private function sorted(array $ships): array
    {
        ksort($ships);

        return $ships;
    }
}
