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
 * The bots' shipyard: a wanted colony ship first (saving for it), then
 * probes, cargo ships and fighters, then defences from what is spare; and
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

    public function testAWantedColonyShipComesFirstOrTheBotSavesForIt(): void
    {
        $levels = $this->developed();

        $this->assertSame([Ships::SHIP_COLONY_SHIP, 1], $this->shipyard->order($levels, [], true, true, self::STOCK, [], 1000));

        // not enough crystal above the reserve: nothing else is built meanwhile
        $this->assertNull($this->shipyard->order($levels, [], true, true, ['metal' => 100000, 'crystal' => 25000, 'deuterium' => 100000], ['crystal' => 10000], 1000));

        // a colony does not build colony ships
        $this->assertSame([Ships::SHIP_SMALL_CARGO_SHIP, 4], $this->shipyard->order($levels, [], false, true, self::STOCK, [], 1000));
    }

    public function testProbesThenCargoThenFightersThenDefences(): void
    {
        $levels = $this->developed();

        $this->assertSame([Ships::SHIP_ESPIONAGE_PROBE, 3], $this->shipyard->order($levels, [], true, false, self::STOCK, [], 1000));
        // a quarter of the stock: 25,000 metal pays 12 cargo ships of the 24 wanted
        $this->assertSame([Ships::SHIP_SMALL_CARGO_SHIP, 12], $this->shipyard->order($levels, [Ships::SHIP_ESPIONAGE_PROBE => 3], true, false, self::STOCK, [], 1000));

        $ships = [Ships::SHIP_ESPIONAGE_PROBE => 3, Ships::SHIP_SMALL_CARGO_SHIP => 24];
        $this->assertSame([Ships::SHIP_LIGHT_FIGHTER, 8], $this->shipyard->order($levels, $ships, true, false, self::STOCK, [], 1000));

        $ships[Ships::SHIP_LIGHT_FIGHTER] = 24;
        $this->assertSame([Defenses::DEFENSE_LIGHT_LASER, 16], $this->shipyard->order($levels, $ships, true, false, self::STOCK, [], 1000));
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

    public function testOnlyResourcesAboveTheNextBuildingAreSpent(): void
    {
        $this->assertSame(
            ['metal' => 500.0, 'crystal' => 0.0, 'deuterium' => 250.0],
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
