<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Bots;

use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\DefensesEnumerator as Defenses;
use App\Core\Enumerators\ResearchEnumerator as Research;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Services\Game\Bots\BotShipyard;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The bots' shipyard: a wanted colony ship first, defences after a raid,
 * then probes, cargo ships and fighters, then defences from what is spare; and
 * the rough combat value the raids are judged by.
 */
#[CoversClass(BotShipyard::class)]
class BotShipyardTest extends TestCase
{
    private const STOCK = ['metal' => 100000, 'crystal' => 100000, 'deuterium' => 100000];

    private BotShipyard $shipyard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shipyard = $this->app->make(BotShipyard::class);
    }

    public function testAWantedColonyShipComesFirst(): void
    {
        $levels = $this->developed();

        // paid from the whole stock, the reserve for the next building notwithstanding
        $this->assertSame([Ships::SHIP_COLONY_SHIP, 1], $this->shipyard->order($levels, [], true, true, false, self::STOCK, self::STOCK, 1000));

        // not enough crystal: the shipyard does not wait for it
        $this->assertSame(
            [Ships::SHIP_ESPIONAGE_PROBE, 3],
            $this->shipyard->order($levels, [], true, true, false, ['metal' => 100000, 'crystal' => 15000, 'deuterium' => 100000], [], 1000)
        );

        // a colony does not build colony ships
        $this->assertSame([Ships::SHIP_SMALL_CARGO_SHIP, 4], $this->shipyard->order($levels, [], false, true, false, self::STOCK, [], 1000));
    }

    public function testProbesThenCargoThenFightersThenDefences(): void
    {
        $levels = $this->developed();

        $this->assertSame([Ships::SHIP_ESPIONAGE_PROBE, 3], $this->shipyard->order($levels, [], true, false, false, self::STOCK, [], 1000));
        // a quarter of the stock: 25,000 metal pays 12 cargo ships of the 24 wanted
        $this->assertSame([Ships::SHIP_SMALL_CARGO_SHIP, 12], $this->shipyard->order($levels, [Ships::SHIP_ESPIONAGE_PROBE => 3], true, false, false, self::STOCK, [], 1000));

        $ships = [Ships::SHIP_ESPIONAGE_PROBE => 3, Ships::SHIP_SMALL_CARGO_SHIP => 24];
        $this->assertSame([Ships::SHIP_LIGHT_FIGHTER, 8], $this->shipyard->order($levels, $ships, true, false, false, self::STOCK, [], 1000));

        $ships[Ships::SHIP_LIGHT_FIGHTER] = 36;
        $this->assertSame([Defenses::DEFENSE_LIGHT_LASER, 16], $this->shipyard->order($levels, $ships, true, false, false, self::STOCK, [], 1000));
    }

    public function testTheWarFleetGrowsWithTheEconomy(): void
    {
        $levels = $this->developed() + [Buildings::BUILDING_ROBOT_FACTORY => 2, Research::RESEARCH_ARMOUR_TECHNOLOGY => 2, Research::RESEARCH_SHIELDING_TECHNOLOGY => 2, Research::RESEARCH_IONIC_TECHNOLOGY => 2];
        $levels[Buildings::BUILDING_METAL_MINE] = 16;
        $levels[Buildings::BUILDING_HANGAR] = 5;
        $levels[Research::RESEARCH_IMPULSE_DRIVE] = 4;
        $levels[Research::RESEARCH_COMBUSTION_DRIVE] = 6;
        $ships = [Ships::SHIP_ESPIONAGE_PROBE => 3, Ships::SHIP_SMALL_CARGO_SHIP => 32, Ships::SHIP_LIGHT_FIGHTER => 48];
        $stock = ['metal' => 1000000, 'crystal' => 1000000, 'deuterium' => 1000000];

        $this->assertSame([Ships::SHIP_RECYCLER, 4], $this->shipyard->order($levels, $ships, true, false, false, $stock, [], 1000));
        $ships[Ships::SHIP_RECYCLER] = 4;
        $this->assertSame([Ships::SHIP_HEAVY_FIGHTER, 32], $this->shipyard->order($levels, $ships, true, false, false, $stock, [], 1000));
        $ships[Ships::SHIP_HEAVY_FIGHTER] = 32;
        // 20 wanted (five per metal mine level above 12), a quarter of the metal pays 12
        $this->assertSame([Ships::SHIP_CRUISER, 12], $this->shipyard->order($levels, $ships, true, false, false, $stock, [], 1000));
    }

    public function testDefencesStopAtTheirCap(): void
    {
        $levels = [Buildings::BUILDING_METAL_MINE => 10, Buildings::BUILDING_HANGAR => 1];
        $budget = ['metal' => 1000000];

        // without the planet's defences there is no cap
        $this->assertSame([Defenses::DEFENSE_ROCKET_LAUNCHER, 500], $this->shipyard->defenceOrder($levels, $budget, 1000));
        // ten rocket launchers per metal mine level
        $this->assertSame([Defenses::DEFENSE_ROCKET_LAUNCHER, 40], $this->shipyard->defenceOrder($levels, $budget, 1000, [Defenses::DEFENSE_ROCKET_LAUNCHER => 60]));
        $this->assertNull($this->shipyard->defenceOrder($levels, $budget, 1000, [Defenses::DEFENSE_ROCKET_LAUNCHER => 100]));
        // twice as many after a raid
        $this->assertSame([Defenses::DEFENSE_ROCKET_LAUNCHER, 100], $this->shipyard->defenceOrder($levels, $budget, 1000, [Defenses::DEFENSE_ROCKET_LAUNCHER => 100], 2));
    }

    public function testARaidedPlanetPutsItsStockAboveTheReserveIntoDefences(): void
    {
        $levels = $this->developed();

        // 60,000 metal and 20,000 crystal above the reserve: 40 light lasers
        $this->assertSame(
            [Defenses::DEFENSE_LIGHT_LASER, 40],
            $this->shipyard->order($levels, [], true, false, true, self::STOCK, ['metal' => 40000, 'crystal' => 80000], 1000)
        );
    }

    public function testSpareResourcesBuyTheBestAllowedDefence(): void
    {
        $this->assertNull($this->shipyard->defenceOrder([], ['metal' => 100000, 'crystal' => 100000], 1000));
        $this->assertSame([Defenses::DEFENSE_ROCKET_LAUNCHER, 5], $this->shipyard->defenceOrder([Buildings::BUILDING_HANGAR => 1], ['metal' => 10000], 1000));
        $this->assertSame([Defenses::DEFENSE_ROCKET_LAUNCHER, 3], $this->shipyard->defenceOrder([Buildings::BUILDING_HANGAR => 1], ['metal' => 10000], 3));

        $lasers = [Buildings::BUILDING_HANGAR => 2, Research::RESEARCH_ENERGY_TECHNOLOGY => 1, Research::RESEARCH_LASER_TECHNOLOGY => 3];
        $this->assertSame([Defenses::DEFENSE_LIGHT_LASER, 2], $this->shipyard->defenceOrder($lasers, ['metal' => 3000, 'crystal' => 1000], 1000));
        // too little crystal for a laser: rocket launchers instead
        $this->assertSame([Defenses::DEFENSE_ROCKET_LAUNCHER, 1], $this->shipyard->defenceOrder($lasers, ['metal' => 3000, 'crystal' => 100], 1000));
    }

    public function testAQuarterAboveTheNextBuildingButAtLeastAFifthIsSpent(): void
    {
        // metal: (10,000 - 2 × 4,000) / 4 = 500, but a fifth is 2,000; deuterium: 250 above a fifth
        $this->assertSame(
            ['metal' => 2000.0, 'crystal' => 200.0, 'deuterium' => 250.0],
            $this->shipyard->spareBudget(['metal' => 10000, 'crystal' => 1000, 'deuterium' => 1000], ['metal' => 4000, 'crystal' => 2000])
        );
    }

    public function testCombatValue(): void
    {
        $this->assertSame(0.0, $this->shipyard->combatValue([]));
        // missiles and unknown ids do not fight
        $this->assertSame(0.0, $this->shipyard->combatValue([Defenses::DEFENSE_INTERPLANETARY_MISSILE => 10, 999 => 5]));

        $rockets = $this->shipyard->combatValue([Defenses::DEFENSE_ROCKET_LAUNCHER => 10]);
        $this->assertGreaterThan(0.0, $rockets);
        $this->assertEqualsWithDelta(2 * $rockets, $this->shipyard->combatValue([Defenses::DEFENSE_ROCKET_LAUNCHER => 20]), 0.001);
        $this->assertGreaterThan(
            $this->shipyard->combatValue([Ships::SHIP_SMALL_CARGO_SHIP => 1]),
            $this->shipyard->combatValue([Ships::SHIP_LIGHT_FIGHTER => 1])
        );

        $this->assertArrayHasKey(Ships::SHIP_LIGHT_FIGHTER, $this->shipyard->combatUnits());
        $this->assertArrayHasKey(Defenses::DEFENSE_ROCKET_LAUNCHER, $this->shipyard->combatUnits());
        $this->assertArrayNotHasKey(Defenses::DEFENSE_ANTI_BALLISTIC_MISSILE, $this->shipyard->combatUnits());
        $this->assertSame('ship_small_cargo_ship', $this->shipyard->column(Ships::SHIP_SMALL_CARGO_SHIP));
    }

    /**
     * A home planet that may build every ship of the plan.
     *
     * @return array<int, int>
     */
    private function developed(): array
    {
        return [
            Buildings::BUILDING_METAL_MINE => 12, Buildings::BUILDING_HANGAR => 4, Buildings::BUILDING_LABORATORY => 3,
            Research::RESEARCH_ENERGY_TECHNOLOGY => 3, Research::RESEARCH_COMBUSTION_DRIVE => 3, Research::RESEARCH_IMPULSE_DRIVE => 3,
            Research::RESEARCH_ESPIONAGE_TECHNOLOGY => 2, Research::RESEARCH_LASER_TECHNOLOGY => 3,
        ];
    }
}
