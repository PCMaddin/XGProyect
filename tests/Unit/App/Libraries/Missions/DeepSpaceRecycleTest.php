<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Recycle;
use App\Services\Game\DeepSpaceDebrisService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Debris in deep space (position 16): only pathfinders harvest it.
 */
#[CoversClass(Recycle::class)]
#[CoversClass(DeepSpaceDebrisService::class)]
class DeepSpaceRecycleTest extends DatabaseTestCase
{
    use SendsHarvestFleets;

    public function testTheFieldGrowsAndShrinks(): void
    {
        $debris = app(DeepSpaceDebrisService::class);
        $debris->add(1, 2, 1000.7, 300, 50);
        $debris->add(1, 2, 500, 0, 60);

        $this->assertSame(['metal' => 1500.0, 'crystal' => 300.0], $debris->get(1, 2));
        $this->assertSame(['metal' => 0.0, 'crystal' => 0.0], $debris->get(1, 3));

        $debris->take(1, 2, 1000, 300);
        $this->assertSame(['metal' => 500.0, 'crystal' => 0.0], $debris->get(1, 2));
        $debris->take(1, 2, 600, 0);
        $this->assertSame(0, DB::table('deep_space_debris')->count());
        $this->assertSame([0.1, 0.2], [$debris->share(\App\Enums\PlayerClass::General), $debris->share(\App\Enums\PlayerClass::Discoverer)]);
    }

    public function testOnlyPathfindersHarvestDeepSpace(): void
    {
        app(DeepSpaceDebrisService::class)->add(1, 2, 30000, 10000, 0);
        $fleetId = $this->fleet([219 => 2, 209 => 5], 16);

        app(Recycle::class)->recycleMission($this->row($fleetId));

        // two pathfinders hold 20.000, the recyclers do not reach deep space
        $fleet = DB::table('fleets')->where('fleet_id', $fleetId)->first(['fleet_resource_metal', 'fleet_resource_crystal', 'fleet_mess']);
        $this->assertNotNull($fleet);
        $this->assertEquals([10000, 10000, 1], [$fleet->fleet_resource_metal, $fleet->fleet_resource_crystal, $fleet->fleet_mess]);
        $this->assertSame(['metal' => 20000.0, 'crystal' => 0.0], app(DeepSpaceDebrisService::class)->get(1, 2));
    }
}
