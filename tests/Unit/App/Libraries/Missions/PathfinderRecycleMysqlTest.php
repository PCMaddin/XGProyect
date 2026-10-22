<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Recycle;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Pathfinders harvest normal debris fields too, together with recyclers
 * (the debris update joins planets and fleets, MySQL/MariaDB only).
 */
#[CoversClass(Recycle::class)]
class PathfinderRecycleMysqlTest extends MysqlTestCase
{
    use SendsHarvestFleets;

    public function testPathfindersAlsoHarvestNormalDebris(): void
    {
        DB::table('planets')->insert(['planet_id' => 9, 'planet_user_id' => 2, 'planet_name' => 'Target', 'planet_galaxy' => 1, 'planet_system' => 2, 'planet_planet' => 8, 'planet_type' => 1, 'planet_debris_metal' => 50000, 'planet_debris_crystal' => 5000]);
        $fleetId = $this->fleet([219 => 1, 209 => 1], 8);

        app(Recycle::class)->recycleMission($this->row($fleetId));

        $fleet = DB::table('fleets')->where('fleet_id', $fleetId)->first(['fleet_resource_metal', 'fleet_resource_crystal']);
        $this->assertNotNull($fleet);
        // 10.000 + 20.000 capacity
        $this->assertEquals([25000, 5000], [$fleet->fleet_resource_metal, $fleet->fleet_resource_crystal]);
    }
}
