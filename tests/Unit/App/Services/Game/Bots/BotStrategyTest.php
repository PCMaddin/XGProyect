<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Bots;

use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\DefensesEnumerator as Defenses;
use App\Core\Enumerators\ResearchEnumerator as Research;
use App\Services\Game\Bots\BotStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The bots' build order: energy, full storages, lagging facilities, then
 * the mines; research along an opening list, then the cheapest step;
 * spare resources into the best allowed defence.
 */
#[CoversClass(BotStrategy::class)]
class BotStrategyTest extends TestCase
{
    private BotStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategy = $this->app->make(BotStrategy::class);
    }

    public function testANewPlanetStartsWithEnergy(): void
    {
        $this->assertSame(Buildings::BUILDING_SOLAR_PLANT, $this->strategy->nextBuilding([], $this->planet(0, 0), true, false));
    }

    public function testShortOfEnergyItSavesForThePowerPlant(): void
    {
        $levels = [Buildings::BUILDING_METAL_MINE => 12, Buildings::BUILDING_SOLAR_PLANT => 10];
        $planet = $this->planet(1000, -1200);
        $planet['planet_metal'] = 100;

        $this->assertSame(Buildings::BUILDING_SOLAR_PLANT, $this->strategy->nextBuilding($levels, $planet, true, false));
    }

    public function testWithEnoughEnergyTheMetalMineComesFirst(): void
    {
        $levels = [Buildings::BUILDING_METAL_MINE => 1, Buildings::BUILDING_SOLAR_PLANT => 2];

        $this->assertSame(Buildings::BUILDING_METAL_MINE, $this->strategy->nextBuilding($levels, $this->planet(100, -20), true, false));
    }

    public function testCrystalFollowsWhenItLagsBehind(): void
    {
        $levels = [Buildings::BUILDING_METAL_MINE => 5, Buildings::BUILDING_CRYSTAL_MINE => 2, Buildings::BUILDING_ROBOT_FACTORY => 1, Buildings::BUILDING_HANGAR => 1, Buildings::BUILDING_LABORATORY => 2];

        $this->assertSame(Buildings::BUILDING_CRYSTAL_MINE, $this->strategy->nextBuilding($levels, $this->planet(500, -300), true, false));
    }

    public function testLaggingFacilitiesComeBeforeTheMines(): void
    {
        $levels = [Buildings::BUILDING_METAL_MINE => 6, Buildings::BUILDING_CRYSTAL_MINE => 5];

        $this->assertSame(Buildings::BUILDING_ROBOT_FACTORY, $this->strategy->nextBuilding($levels, $this->planet(500, -300), true, false));
    }

    public function testAMissingResourceThatIsNotProducedGetsItsMineFirst(): void
    {
        $levels = [Buildings::BUILDING_METAL_MINE => 6, Buildings::BUILDING_CRYSTAL_MINE => 5];
        $planet = $this->planet(500, -300);
        $planet['planet_deuterium'] = 0;
        $planet['planet_deuterium_perhour'] = 0;

        // the robotics factory needs deuterium the planet does not produce yet
        $this->assertSame(Buildings::BUILDING_DEUTERIUM_SINTETIZER, $this->strategy->nextBuilding($levels, $planet, true, false));

        $planet['planet_deuterium'] = 1000;
        $this->assertSame(Buildings::BUILDING_ROBOT_FACTORY, $this->strategy->nextBuilding($levels, $planet, true, false));
    }

    public function testTheMostUrgentStepItCanPayWinsAndOtherwiseItSaves(): void
    {
        $levels = [Buildings::BUILDING_METAL_MINE => 6, Buildings::BUILDING_CRYSTAL_MINE => 5, Buildings::BUILDING_DEUTERIUM_SINTETIZER => 2];
        $planet = $this->planet(500, -300);
        $planet['planet_deuterium'] = 50;

        // the robotics factory waits for its deuterium, the metal mine does not
        $this->assertSame(Buildings::BUILDING_METAL_MINE, $this->strategy->nextBuilding($levels, $planet, false, true));

        // nothing payable: save for the most urgent step
        $planet['planet_metal'] = 0;
        $this->assertSame(Buildings::BUILDING_ROBOT_FACTORY, $this->strategy->nextBuilding($levels, $planet, false, true));
    }

    public function testTheLaboratoryWaitsWhileResearchRuns(): void
    {
        $levels = [Buildings::BUILDING_METAL_MINE => 6, Buildings::BUILDING_CRYSTAL_MINE => 5, Buildings::BUILDING_ROBOT_FACTORY => 2, Buildings::BUILDING_HANGAR => 2];

        $this->assertSame(Buildings::BUILDING_LABORATORY, $this->strategy->nextBuilding($levels, $this->planet(500, -300), true, false));
        // without the laboratory the deuterium synthesizer is the next behind (metal 6, deuterium 0)
        $this->assertSame(Buildings::BUILDING_DEUTERIUM_SINTETIZER, $this->strategy->nextBuilding($levels, $this->planet(500, -300), true, true));
    }

    public function testAFullStorageIsExtended(): void
    {
        $planet = $this->planet(500, -300);
        $planet['planet_crystal'] = 9500;

        $this->assertSame(Buildings::BUILDING_CRYSTAL_STORE, $this->strategy->nextBuilding([Buildings::BUILDING_METAL_MINE => 1], $planet, true, false));
    }

    public function testABuildingThatCostsMoreThanTheStorageHoldsGetsAStorageFirst(): void
    {
        $levels = [Buildings::BUILDING_METAL_MINE => 30, Buildings::BUILDING_CRYSTAL_MINE => 28, Buildings::BUILDING_DEUTERIUM_SINTETIZER => 25,
            Buildings::BUILDING_ROBOT_FACTORY => 10, Buildings::BUILDING_HANGAR => 12, Buildings::BUILDING_LABORATORY => 12, Buildings::BUILDING_NANO_FACTORY => 3];

        $this->assertSame(Buildings::BUILDING_METAL_STORE, $this->strategy->nextBuilding($levels, $this->planet(50000, -100), true, false));
    }

    public function testResearchFollowsTheOpeningAndThenTheCheapestStep(): void
    {
        $this->assertNull($this->strategy->nextResearch([]));
        $this->assertSame(Research::RESEARCH_ENERGY_TECHNOLOGY, $this->strategy->nextResearch([Buildings::BUILDING_LABORATORY => 1]));
        $this->assertSame(Research::RESEARCH_COMBUSTION_DRIVE, $this->strategy->nextResearch([Buildings::BUILDING_LABORATORY => 1, Research::RESEARCH_ENERGY_TECHNOLOGY => 1]));

        $done = [Buildings::BUILDING_LABORATORY => 10, Buildings::BUILDING_HANGAR => 5, Research::RESEARCH_ENERGY_TECHNOLOGY => 3, Research::RESEARCH_COMBUSTION_DRIVE => 2,
            Research::RESEARCH_ESPIONAGE_TECHNOLOGY => 2, Research::RESEARCH_COMPUTER_TECHNOLOGY => 2, Research::RESEARCH_LASER_TECHNOLOGY => 6,
            Research::RESEARCH_WEAPONS_TECHNOLOGY => 2, Research::RESEARCH_SHIELDING_TECHNOLOGY => 2, Research::RESEARCH_ARMOUR_TECHNOLOGY => 2,
            Research::RESEARCH_IMPULSE_DRIVE => 2, Research::RESEARCH_IONIC_TECHNOLOGY => 2];

        // after the opening: the cheapest next level, shielding 3 (3,200) before combustion drive 3 (4,000)
        $this->assertSame(Research::RESEARCH_SHIELDING_TECHNOLOGY, $this->strategy->nextResearch($done));
    }

    public function testSpareResourcesBuyTheBestAllowedDefence(): void
    {
        $this->assertNull($this->strategy->defenceOrder([], ['metal' => 100000, 'crystal' => 100000], 1000));
        $this->assertSame([Defenses::DEFENSE_ROCKET_LAUNCHER, 5], $this->strategy->defenceOrder([Buildings::BUILDING_HANGAR => 1], ['metal' => 10000], 1000));
        $this->assertSame([Defenses::DEFENSE_ROCKET_LAUNCHER, 3], $this->strategy->defenceOrder([Buildings::BUILDING_HANGAR => 1], ['metal' => 10000], 3));

        $lasers = [Buildings::BUILDING_HANGAR => 2, Research::RESEARCH_ENERGY_TECHNOLOGY => 1, Research::RESEARCH_LASER_TECHNOLOGY => 3];
        $this->assertSame([Defenses::DEFENSE_LIGHT_LASER, 2], $this->strategy->defenceOrder($lasers, ['metal' => 3000, 'crystal' => 1000], 1000));
        // too little crystal for a laser: rocket launchers instead
        $this->assertSame([Defenses::DEFENSE_ROCKET_LAUNCHER, 1], $this->strategy->defenceOrder($lasers, ['metal' => 3000, 'crystal' => 100], 1000));
    }

    public function testOnlyResourcesAboveTheNextBuildingAreSpent(): void
    {
        $this->assertSame(
            ['metal' => 500.0, 'crystal' => 0.0, 'deuterium' => 250.0],
            $this->strategy->spareBudget(['metal' => 10000, 'crystal' => 1000, 'deuterium' => 1000], ['metal' => 4000, 'crystal' => 2000])
        );
    }

    /**
     * @return array<string, float|int>
     */
    private function planet(int $energyMax, int $energyUsed): array
    {
        return [
            'planet_metal' => 5000, 'planet_metal_max' => 10000,
            'planet_crystal' => 5000, 'planet_crystal_max' => 10000,
            'planet_deuterium' => 5000, 'planet_deuterium_max' => 10000,
            'planet_metal_perhour' => 100, 'planet_crystal_perhour' => 50, 'planet_deuterium_perhour' => 20,
            'planet_energy_max' => $energyMax, 'planet_energy_used' => $energyUsed,
        ];
    }
}
