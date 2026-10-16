<?php

declare(strict_types=1);

namespace Tests\Unit\App\Enums;

use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Enums\PlayerClass;
use App\Services\Game\Formulas\ClassProductionService;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\Game\Formulas\FleetsService;
use App\Libraries\Missions\CombatMission;
use ReflectionMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * The bonuses of the player classes of OGame 7.
 */
#[CoversClass(PlayerClass::class)]
#[CoversClass(ClassProductionService::class)]
class PlayerClassTest extends DatabaseTestCase
{
    public function testEachClassBuildsItsOwnShip(): void
    {
        $this->assertTrue(PlayerClass::Collector->canBuild(Ships::SHIP_CRAWLER));
        $this->assertFalse(PlayerClass::General->canBuild(Ships::SHIP_CRAWLER));
        $this->assertTrue(PlayerClass::General->canBuild(Ships::SHIP_REAPER));
        $this->assertFalse(PlayerClass::Discoverer->canBuild(Ships::SHIP_REAPER));
        $this->assertTrue(PlayerClass::Discoverer->canBuild(Ships::SHIP_PATHFINDER));
        $this->assertFalse(PlayerClass::None->canBuild(Ships::SHIP_PATHFINDER));
        $this->assertTrue(PlayerClass::None->canBuild(Ships::SHIP_BATTLECRUISER));
    }

    public function testFleetBonuses(): void
    {
        $fleets = app(FleetsService::class);

        // collectors: cargo ships twice as fast and 25 % roomier
        $this->assertSame(2 * $fleets->getShipSpeed(Ships::SHIP_BIG_CARGO_SHIP, 0, 0, 0), $fleets->getShipSpeed(Ships::SHIP_BIG_CARGO_SHIP, 0, 0, 0, PlayerClass::Collector));
        $this->assertSame(31250, $fleets->getMaxStorage(25000, 0, Ships::SHIP_BIG_CARGO_SHIP, PlayerClass::Collector));
        // hyperspace technology 10 (+50 %) and the class (+25 %) add up
        $this->assertSame(43750, $fleets->getMaxStorage(25000, 10, Ships::SHIP_BIG_CARGO_SHIP, PlayerClass::Collector));

        // generals: combat ships and recyclers twice as fast, not deathstars; a quarter less fuel
        $this->assertSame(2 * $fleets->getShipSpeed(Ships::SHIP_CRUISER, 0, 3, 0), $fleets->getShipSpeed(Ships::SHIP_CRUISER, 0, 3, 0, PlayerClass::General));
        $this->assertSame(2 * $fleets->getShipSpeed(Ships::SHIP_RECYCLER, 6, 0, 0), $fleets->getShipSpeed(Ships::SHIP_RECYCLER, 6, 0, 0, PlayerClass::General));
        $this->assertSame($fleets->getShipSpeed(Ships::SHIP_DEATHSTAR, 0, 0, 7), $fleets->getShipSpeed(Ships::SHIP_DEATHSTAR, 0, 0, 7, PlayerClass::General));
        $this->assertSame(225, $fleets->shipConsumption(Ships::SHIP_CRUISER, 0, 0, 0, PlayerClass::General));
        $this->assertSame(24000, $fleets->getMaxStorage(20000, 0, Ships::SHIP_RECYCLER, PlayerClass::General));
        $this->assertSame(20000, $fleets->getMaxStorage(20000, 0, Ships::SHIP_RECYCLER, PlayerClass::Collector));

        // slots
        $this->assertSame(6, $fleets->getMaxFleets(3, false, PlayerClass::General));
        $this->assertSame(4, $fleets->getMaxFleets(3, false));
        $this->assertSame(5, $fleets->getMaxExpeditions(9, PlayerClass::Discoverer));
        $this->assertSame(3, $fleets->getMaxExpeditions(9, PlayerClass::General));
    }

    public function testOtherBonuses(): void
    {
        $this->assertSame([2, 0, 0], [PlayerClass::General->combatTechBonus(), PlayerClass::Collector->combatTechBonus(), PlayerClass::None->combatTechBonus()]);
        $this->assertSame(5, PlayerClass::General->moonFieldBonus());
        $this->assertSame(0.75, PlayerClass::Discoverer->researchTimeFactor());
        $this->assertSame(1.2, PlayerClass::Discoverer->phalanxRangeFactor());
        $this->assertSame(0.75, PlayerClass::Discoverer->inactiveLootShare());
        $this->assertSame(0.5, PlayerClass::General->inactiveLootShare());
        $this->assertSame(1.5, PlayerClass::Discoverer->expeditionFindFactor());
        $this->assertSame(0.5, PlayerClass::Discoverer->expeditionEnemyFactor());
        $this->assertSame(1.1, PlayerClass::Discoverer->planetSizeFactor());
    }

    public function testDiscoverersResearchFasterAndPlunderInactivePlayersMore(): void
    {
        DB::table('options')->insert(['name' => 'game_speed', 'value' => '1', 'type' => 'int']);
        $developments = app(DevelopmentsService::class);
        $normal = $developments->developmentTime(113, 5, 0, 0, 10, 0, false);

        $this->assertSame((int) floor($normal * 0.75), $developments->developmentTime(113, 5, 0, 0, 10, 0, false, PlayerClass::Discoverer));

        $plunder = new ReflectionMethod(CombatMission::class, 'plunder');
        $this->assertSame(['metal' => 30000.0, 'crystal' => 0.0, 'deuterium' => 0.0], $plunder->invoke(null, 1000000, 40000, 0, 0, 0.75));
        $this->assertSame(['metal' => 20000.0, 'crystal' => 0.0, 'deuterium' => 0.0], $plunder->invoke(null, 1000000, 40000, 0, 0));
    }

    public function testCollectorsAndCrawlersRaiseTheProduction(): void
    {
        $service = app(ClassProductionService::class);
        $mines = ['metal' => 10000.0, 'crystal' => 6000.0, 'deuterium' => 2000.0];

        // collectors: +25 % mines, +10 % energy
        $this->assertSame(
            ['metal' => 2500.0, 'crystal' => 1500.0, 'deuterium' => 500.0, 'energyMax' => 300.0, 'energyUsed' => 0.0],
            $service->extras($mines, 3000, 0, 60, 10, PlayerClass::Collector, false)
        );

        // 100 crawlers at 100 % for others: +2 %, 50 energy each
        $this->assertSame(
            ['metal' => 200.0, 'crystal' => 120.0, 'deuterium' => 40.0, 'energyMax' => 0.0, 'energyUsed' => 5000.0],
            $service->extras($mines, 3000, 100, 60, 10, PlayerClass::General, false)
        );

        // only 8 per mine level work: 10 mine levels, 500 crawlers -> 80
        $this->assertSame(80, $service->workingCrawlers(500, 10, PlayerClass::None, true));
        $this->assertSame(88, $service->workingCrawlers(500, 10, PlayerClass::Collector, true));

        // collectors at 150 %: 0,02 % × 1,5 × 1,5 per crawler, twice the energy; at most +50 %
        $overloaded = $service->extras($mines, 0, 100, 60, 15, PlayerClass::Collector, false);
        $this->assertSame(floor(10000 * (0.25 + 100 * 0.0002 * 1.5 * 1.5)), $overloaded['metal']);
        $this->assertSame(10000.0, $overloaded['energyUsed']);
        // others cannot overload
        $this->assertSame(5000.0, $service->extras($mines, 0, 100, 60, 15, PlayerClass::None, false)['energyUsed']);
        $this->assertSame(5000.0, $service->extras($mines, 0, 100000, 100000, 10, PlayerClass::None, false)['metal']);
    }
}
