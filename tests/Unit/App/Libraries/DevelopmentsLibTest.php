<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\DevelopmentsLib;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(DevelopmentsLib::class)]
class DevelopmentsLibTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function buildingPageProvider(): iterable
    {
        yield 'metal mine is a supply' => [1, 'supplies'];
        yield 'deuterium tank is a supply' => [24, 'supplies'];
        yield 'robotics factory is a facility' => [14, 'facilities'];
        yield 'terraformer is a facility' => [33, 'facilities'];
        yield 'unknown element falls back to overview' => [999, 'overview'];
    }

    #[DataProvider('buildingPageProvider')]
    public function testSetBuildingPageClassifiesElements(int $element, string $expected): void
    {
        $this->assertSame($expected, DevelopmentsLib::setBuildingPage($element));
    }

    public function testIsLabWorking(): void
    {
        $this->assertTrue(DevelopmentsLib::isLabWorking(['research_current_research' => 108]));
        $this->assertFalse(DevelopmentsLib::isLabWorking(['research_current_research' => 0]));
        // a missing key is treated as "not working" rather than a warning
        $this->assertFalse(DevelopmentsLib::isLabWorking([]));
    }

    public function testIsShipyardWorking(): void
    {
        $this->assertTrue(DevelopmentsLib::isShipyardWorking(['planet_b_hangar' => 42]));
        $this->assertFalse(DevelopmentsLib::isShipyardWorking(['planet_b_hangar' => 0]));
        $this->assertFalse(DevelopmentsLib::isShipyardWorking([]));
    }

    public function testFormatedDevelopmentTimeCarriesThePrefix(): void
    {
        $result = DevelopmentsLib::formatedDevelopmentTime(3661, 'Dauer: ');

        $this->assertStringStartsWith('<br>Dauer: ', $result);
    }
}
