<?php

declare(strict_types=1);

namespace Tests\Unit\App\Support;

use App\Services\Game\Formulas\FleetsService;
use App\Support\FleetShips;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(FleetShips::class)]
#[CoversClass(FleetsService::class)]
class FleetShipsTest extends TestCase
{
    public function testShipsArrayRoundTrips(): void
    {
        $serialized = FleetShips::encode([202 => 5, 203 => 12]);

        $this->assertSame([202 => 5, 203 => 12], FleetShips::decode($serialized));
    }

    public function testCountsAreNormalisedToInt(): void
    {
        // legacy rows can carry numeric-string counts; callers do arithmetic on them
        $serialized = serialize(['202' => '5', '203' => '0']);

        $this->assertSame([202 => 5, 203 => 0], FleetShips::decode($serialized));
    }

    public function testEmptyFleetArrayIsAnEmptyArray(): void
    {
        // an empty fleet_array (no ships) is a normal state, not an error
        $this->assertSame([], FleetShips::decode(''));
    }

    public function testEmbeddedObjectsAreNotInstantiated(): void
    {
        // a tampered fleet_array must not be able to build objects on unserialize
        $payload = serialize([new \stdClass()]);
        $result = FleetShips::decode($payload);

        // the __PHP_Incomplete_Class placeholder normalises to 0, never a live object
        $this->assertSame([0 => 0], $result);
    }

    public function testCarriesResources(): void
    {
        $this->assertTrue(app(FleetsService::class)->carriesResources(['fleet_resource_metal' => 100, 'fleet_resource_crystal' => 0, 'fleet_resource_deuterium' => 0]));
        $this->assertTrue(app(FleetsService::class)->carriesResources(['fleet_resource_metal' => 0, 'fleet_resource_crystal' => 0, 'fleet_resource_deuterium' => 5]));
        $this->assertFalse(app(FleetsService::class)->carriesResources(['fleet_resource_metal' => 0, 'fleet_resource_crystal' => 0, 'fleet_resource_deuterium' => 0]));
        $this->assertFalse(app(FleetsService::class)->carriesResources([]));
    }
}
