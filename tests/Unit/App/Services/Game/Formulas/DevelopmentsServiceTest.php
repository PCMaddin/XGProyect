<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Formulas;

use App\Core\Enumerators\ShipsEnumerator;
use App\Core\Enumerators\ResearchEnumerator;
use App\Core\Enumerators\BuildingsEnumerator;
use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\Game\Formulas\FormulasService;
use App\Services\SettingsService;
use PHPUnit\Framework\TestCase;

class DevelopmentsServiceTest extends TestCase
{
    private DevelopmentsService $service;

    protected function setUp(): void
    {
        if (!defined('FIELDS_BY_TERRAFORMER')) {
            define('FIELDS_BY_TERRAFORMER', 5);
        }

        if (!defined('TECHNOCRATE_SPEED')) {
            define('TECHNOCRATE_SPEED', 0.25);
        }

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getInt')->willReturn(2500);

        $registry = new GameObjectRegistry();
        $formulas = new FormulasService($settingsService);

        $this->service = new DevelopmentsService($registry, $formulas);
    }

    public function testSetBuildingPageSupplies(): void
    {
        $this->assertEquals('supplies', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_METAL_MINE));
        $this->assertEquals('supplies', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_CRYSTAL_MINE));
        $this->assertEquals('supplies', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_DEUTERIUM_SINTETIZER));
        $this->assertEquals('supplies', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_SOLAR_PLANT));
        $this->assertEquals('supplies', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_FUSION_REACTOR));
        $this->assertEquals('supplies', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_METAL_STORE));
    }

    public function testSetBuildingPageFacilities(): void
    {
        $this->assertEquals('facilities', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_ROBOT_FACTORY));
        $this->assertEquals('facilities', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_HANGAR));
        $this->assertEquals('facilities', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_LABORATORY));
        $this->assertEquals('facilities', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_TERRAFORMER));
        $this->assertEquals('facilities', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_MISSILE_SILO));
    }

    public function testSetBuildingPageMoon(): void
    {
        $this->assertEquals('facilities', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_MONDBASIS));
        $this->assertEquals('facilities', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_PHALANX));
        $this->assertEquals('facilities', $this->service->setBuildingPage(BuildingsEnumerator::BUILDING_JUMP_GATE));
    }

    public function testSetBuildingPageNonBuilding(): void
    {
        // Ship ID should return 'overview'
        $this->assertEquals('overview', $this->service->setBuildingPage(ShipsEnumerator::SHIP_SMALL_CARGO_SHIP));
    }

    public function testSetBuildingPageInvalidId(): void
    {
        $this->assertEquals('overview', $this->service->setBuildingPage(9999));
    }

    public function testMaxFields(): void
    {
        // 163 + (5 * 5) = 188
        $this->assertEquals(188, $this->service->maxFields(163, 5));
    }

    public function testMaxFieldsNoTerraformer(): void
    {
        $this->assertEquals(163, $this->service->maxFields(163, 0));
    }

    public function testDevelopmentPriceMetalMineLevel0(): void
    {
        // Metal mine: metal=60, crystal=15, factor=1.5, level=0
        // round(60 * 1.5^0) = 60, round(15 * 1.5^0) = 15
        $cost = $this->service->developmentPrice(BuildingsEnumerator::BUILDING_METAL_MINE, 0);

        $this->assertEquals(60, $cost['metal']);
        $this->assertEquals(15, $cost['crystal']);
    }

    public function testDevelopmentPriceMetalMineLevel5(): void
    {
        // Metal mine: metal=60, crystal=15, factor=1.5, level=5
        // round(60 * 1.5^5) = 455, round(15 * 1.5^5) = 114
        $cost = $this->service->developmentPrice(BuildingsEnumerator::BUILDING_METAL_MINE, 5);

        $this->assertEquals(456, $cost['metal']);
        $this->assertEquals(114, $cost['crystal']);
    }

    public function testDevelopmentPriceNonIncremental(): void
    {
        // Non-incremental: just floor of base price
        $cost = $this->service->developmentPrice(BuildingsEnumerator::BUILDING_METAL_MINE, 10, false);

        $this->assertEquals(60, $cost['metal']);
        $this->assertEquals(15, $cost['crystal']);
    }

    public function testIsDevelopmentPayableTrue(): void
    {
        $resources = [
            'planet_metal' => 1000,
            'planet_crystal' => 1000,
            'planet_deuterium' => 1000,
            'planet_energy_max' => 0,
        ];

        $this->assertTrue($this->service->isDevelopmentPayable($resources, BuildingsEnumerator::BUILDING_METAL_MINE, 0));
    }

    public function testIsDevelopmentPayableFalse(): void
    {
        $resources = [
            'planet_metal' => 0,
            'planet_crystal' => 0,
            'planet_deuterium' => 0,
            'planet_energy_max' => 0,
        ];

        $this->assertFalse($this->service->isDevelopmentPayable($resources, BuildingsEnumerator::BUILDING_METAL_MINE, 5));
    }

    public function testDevelopmentTimeBuildingReturnsPositive(): void
    {
        $time = $this->service->developmentTime(
            BuildingsEnumerator::BUILDING_METAL_MINE,
            5,
            5,  // robotics factory
            0,  // nanite factory
            0,  // total lab level
            0,  // astrophysics level
            false
        );

        $this->assertGreaterThan(0, $time);
    }

    public function testDevelopmentTimeResearch(): void
    {
        $time = $this->service->developmentTime(
            ResearchEnumerator::RESEARCH_ENERGY_TECHNOLOGY,
            1,
            0,  // robotics factory (not relevant)
            0,  // nanite factory (not relevant)
            5,  // total lab level
            0,  // astrophysics level
            false
        );

        $this->assertGreaterThan(0, $time);
    }

    public function testDevelopmentTimeResearchWithTechnocrate(): void
    {
        $timeWithout = $this->service->developmentTime(
            ResearchEnumerator::RESEARCH_ENERGY_TECHNOLOGY,
            1,
            0,
            0,
            5,
            0,
            false
        );

        $timeWith = $this->service->developmentTime(
            ResearchEnumerator::RESEARCH_ENERGY_TECHNOLOGY,
            1,
            0,
            0,
            5,
            0,
            true
        );

        $this->assertLessThan($timeWithout, $timeWith);
    }

    public function testTearDownTime(): void
    {
        $time = $this->service->tearDownTime(
            BuildingsEnumerator::BUILDING_METAL_MINE,
            5,
            3,  // robotics factory
            0   // nanite factory
        );

        $this->assertGreaterThan(0, $time);
    }

    public function testIsDevelopmentAllowedNoRequirements(): void
    {
        // Metal mine has no requirements
        $this->assertTrue($this->service->isDevelopmentAllowed(BuildingsEnumerator::BUILDING_METAL_MINE, []));
    }

    public function testIsDevelopmentAllowedMet(): void
    {
        // Fusion reactor requires deuterium synth 5 and energy tech 3
        $levels = [3 => 5, 113 => 3];
        $this->assertTrue($this->service->isDevelopmentAllowed(BuildingsEnumerator::BUILDING_FUSION_REACTOR, $levels));
    }

    public function testIsDevelopmentAllowedNotMet(): void
    {
        // Fusion reactor requires deuterium synth 5 and energy tech 3
        $levels = [3 => 2, 113 => 1];
        $this->assertFalse($this->service->isDevelopmentAllowed(BuildingsEnumerator::BUILDING_FUSION_REACTOR, $levels));
    }

    public function testIsDevelopmentAllowedPartiallyMet(): void
    {
        // Only one requirement met
        $levels = [3 => 5, 113 => 1];
        $this->assertFalse($this->service->isDevelopmentAllowed(BuildingsEnumerator::BUILDING_FUSION_REACTOR, $levels));
    }

    public function testIsLabWorking(): void
    {
        $this->assertTrue($this->service->isLabWorking(106));
        $this->assertFalse($this->service->isLabWorking(0));
    }

    public function testIsShipyardWorking(): void
    {
        $this->assertTrue($this->service->isShipyardWorking(1));
        $this->assertFalse($this->service->isShipyardWorking(0));
    }

    public function testAreFieldsAvailable(): void
    {
        // 100 current < 163 + 0 = 163 max
        $this->assertTrue($this->service->areFieldsAvailable(100, 163, 0));
    }

    public function testAreFieldsNotAvailable(): void
    {
        // 163 current = 163 + 0 = 163 max
        $this->assertFalse($this->service->areFieldsAvailable(163, 163, 0));
    }

    public function testAreFieldsAvailableWithTerraformer(): void
    {
        // 163 current < 163 + (2 * 5) = 173 max
        $this->assertTrue($this->service->areFieldsAvailable(163, 163, 2));
    }
}
