<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\FleetsLib;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(FleetsLib::class)]
class FleetsLibTest extends TestCase
{
    public function testShipsArrayRoundTrips(): void
    {
        $serialized = FleetsLib::setFleetShipsArray([202 => 5, 203 => 12]);

        $this->assertSame([202 => 5, 203 => 12], FleetsLib::getFleetShipsArray($serialized));
    }

    public function testCountsAreNormalisedToInt(): void
    {
        // legacy rows can carry numeric-string counts; callers do arithmetic on them
        $serialized = serialize(['202' => '5', '203' => '0']);

        $this->assertSame([202 => 5, 203 => 0], FleetsLib::getFleetShipsArray($serialized));
    }

    public function testEmptyFleetArrayIsAnEmptyArray(): void
    {
        // an empty fleet_array (no ships) is a normal state, not an error
        $this->assertSame([], FleetsLib::getFleetShipsArray(''));
    }

    public function testEmbeddedObjectsAreNotInstantiated(): void
    {
        // a tampered fleet_array must not be able to build objects on unserialize
        $payload = serialize([new \stdClass()]);
        $result = FleetsLib::getFleetShipsArray($payload);

        // the __PHP_Incomplete_Class placeholder normalises to 0, never a live object
        $this->assertSame([0 => 0], $result);
    }

    public function testHasResources(): void
    {
        $this->assertTrue(FleetsLib::hasResources(['fleet_resource_metal' => 100, 'fleet_resource_crystal' => 0, 'fleet_resource_deuterium' => 0]));
        $this->assertTrue(FleetsLib::hasResources(['fleet_resource_metal' => 0, 'fleet_resource_crystal' => 0, 'fleet_resource_deuterium' => 5]));
        $this->assertFalse(FleetsLib::hasResources(['fleet_resource_metal' => 0, 'fleet_resource_crystal' => 0, 'fleet_resource_deuterium' => 0]));
        $this->assertFalse(FleetsLib::hasResources([]));
    }
}
