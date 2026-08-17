<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Models\BuildingQueue;
use App\Models\Buildings;
use App\Models\Planets;
use App\Services\Game\BuildingQueueService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;
use Xgp\App\Core\Enumerators\BuildingsEnumerator as BuildingsEnum;

#[CoversClass(BuildingQueueService::class)]
class BuildingQueueServiceTest extends DatabaseTestCase
{
    private const METAL_MINE = BuildingsEnum::BUILDING_METAL_MINE;

    private function seedSettings(): void
    {
        DB::table('options')->insert([
            ['name' => 'game_speed', 'value' => '1', 'type' => '32'],
            ['name' => 'initial_fields', 'value' => '163', 'type' => '32'],
        ]);
    }

    private function makePlanet(float $metal = 100000, float $crystal = 100000, float $deuterium = 100000): Planets
    {
        $this->seedSettings();

        $planet = new Planets();
        $planet->forceFill([
            'planet_id' => 1,
            'planet_user_id' => 1,
            'planet_name' => 'Homeworld',
            'planet_galaxy' => 1,
            'planet_system' => 1,
            'planet_planet' => 1,
            'planet_field_current' => 5,
            'planet_field_max' => 163,
            'planet_metal' => $metal,
            'planet_crystal' => $crystal,
            'planet_deuterium' => $deuterium,
            'planet_b_building' => 0,
        ])->save();

        (new Buildings())->forceFill(['building_planet_id' => 1])->save();

        return $planet->fresh() ?? $planet;
    }

    private function service(): BuildingQueueService
    {
        return app(BuildingQueueService::class);
    }

    public function testAddQueuesABuildingAndChargesResources(): void
    {
        $planet = $this->makePlanet();

        $result = $this->service()->add($planet, [], self::METAL_MINE, 'build');

        $this->assertTrue($result);

        $row = BuildingQueue::query()->where('planet_id', 1)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, $row->position);
        $this->assertSame(self::METAL_MINE, $row->building_id);
        $this->assertSame('build', $row->mode);
        $this->assertSame(1, $row->target_level);

        // first item of an empty queue charges resources up front
        $this->assertLessThan(100000, (float) $planet->fresh()?->planet_metal);
    }

    public function testSecondBuildTakesTheNextPositionWithoutImmediateCharge(): void
    {
        $planet = $this->makePlanet();
        $service = $this->service();

        $service->add($planet, [], self::METAL_MINE, 'build');
        $metalAfterFirst = (float) $planet->fresh()?->planet_metal;

        $service->add($planet->fresh() ?? $planet, [], self::METAL_MINE, 'build');

        $positions = BuildingQueue::query()->where('planet_id', 1)->orderBy('position')->pluck('position')->all();
        $this->assertSame([1, 2], $positions);

        // only the head of the queue is paid immediately
        $this->assertSame($metalAfterFirst, (float) $planet->fresh()?->planet_metal);
    }

    public function testCancelFirstRefundsResourcesAndEmptiesTheQueue(): void
    {
        $planet = $this->makePlanet();
        $service = $this->service();

        $service->add($planet, [], self::METAL_MINE, 'build');

        $result = $service->cancelFirst($planet->fresh() ?? $planet, []);

        $this->assertTrue($result);
        $this->assertSame(0, BuildingQueue::query()->where('planet_id', 1)->count());

        $reloaded = $planet->fresh();
        // charging on add and refunding on cancel at the same level cancels out
        $this->assertSame(100000.0, (float) $reloaded?->planet_metal);
        $this->assertSame(0, (int) $reloaded?->planet_b_building);
    }

    public function testCancelFirstOnAnEmptyQueueReturnsFalse(): void
    {
        $planet = $this->makePlanet();

        $this->assertFalse($this->service()->cancelFirst($planet, []));
    }

    public function testQueueIsCappedAtFiveEntries(): void
    {
        $planet = $this->makePlanet();

        for ($position = 1; $position <= 5; $position++) {
            BuildingQueue::create([
                'planet_id' => 1,
                'position' => $position,
                'building_id' => self::METAL_MINE,
                'target_level' => $position,
                'mode' => 'build',
                'duration' => 10,
                'end_time' => time() + 10,
            ]);
        }

        $this->assertFalse($this->service()->add($planet, [], self::METAL_MINE, 'build'));
        $this->assertSame(5, BuildingQueue::query()->where('planet_id', 1)->count());
    }

    public function testGetQueueDataReportsLengthAndDestroyCount(): void
    {
        $planet = $this->makePlanet();

        BuildingQueue::create(['planet_id' => 1, 'position' => 1, 'building_id' => self::METAL_MINE, 'target_level' => 1, 'mode' => 'build', 'duration' => 10, 'end_time' => time() + 10]);
        BuildingQueue::create(['planet_id' => 1, 'position' => 2, 'building_id' => self::METAL_MINE, 'target_level' => 0, 'mode' => 'destroy', 'duration' => 10, 'end_time' => time() + 20]);

        $data = $this->service()->getQueueData($planet);

        $this->assertSame(2, $data['length']);
        $this->assertSame(1, $data['to_destroy']);
        $this->assertCount(2, $data['items']);
    }
}
