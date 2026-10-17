<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Admin;

use App\Models\BuildingQueue;
use App\Models\ResearchQueue;
use App\Services\Admin\ResetService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Regression: resetting the building or research queues only cleared the
 * planets' cache columns; the orders in building_queues and research_queues
 * kept running and were completed afterwards.
 */
#[CoversClass(ResetService::class)]
class ResetServiceQueuesTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->insert(['id' => 1, 'name' => 'Player', 'email' => 'player@example.com', 'password' => 'x', 'home_planet_id' => 1, 'current_planet' => 1]);
        DB::table('planets')->insert(['planet_id' => 1, 'planet_user_id' => 1, 'planet_b_building' => 123, 'planet_b_tech' => 456, 'planet_b_tech_id' => 113]);
        BuildingQueue::create(['planet_id' => 1, 'position' => 1, 'building_id' => 1, 'target_level' => 1, 'mode' => 'build', 'duration' => 10, 'end_time' => 123]);
        ResearchQueue::create(['user_id' => 1, 'planet_id' => 1, 'position' => 1, 'tech_id' => 113, 'target_level' => 1, 'duration' => 10, 'end_time' => 456]);
    }

    public function testResettingTheBuildingQueuesEmptiesThem(): void
    {
        app(ResetService::class)->resetBuildingsQueues();

        $this->assertSame(0, BuildingQueue::count());
        $this->assertSame(1, ResearchQueue::count());
        $this->assertSame(0, $this->int(DB::table('planets')->value('planet_b_building')));
    }

    public function testResettingTheResearchQueuesEmptiesThem(): void
    {
        app(ResetService::class)->resetResearchQueues();

        $this->assertSame(0, ResearchQueue::count());
        $this->assertSame(1, BuildingQueue::count());
        $this->assertSame(0, $this->int(DB::table('planets')->value('planet_b_tech_id')));
    }

    private function int(mixed $value): int
    {
        $this->assertIsNumeric($value);

        return (int) $value;
    }
}
