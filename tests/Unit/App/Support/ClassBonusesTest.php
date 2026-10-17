<?php

declare(strict_types=1);

namespace Tests\Unit\App\Support;

use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Enums\AllianceClass;
use App\Enums\PlayerClass;
use App\Services\Game\Formulas\ClassProductionService;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\Formulas\ProductionService;
use App\Services\Game\PlayerClassService;
use App\Support\ClassBonuses;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The bonuses of the alliance classes of OGame 7, alone and together with
 * the player classes.
 */
#[CoversClass(ClassBonuses::class)]
#[CoversClass(AllianceClass::class)]
class ClassBonusesTest extends TestCase
{
    public function testTradersFlyCargoShipsFasterProduceAndStoreMore(): void
    {
        $trader = new ClassBonuses(alliance: AllianceClass::Trader);
        $fleets = app(FleetsService::class);
        $base = $fleets->getShipSpeed(Ships::SHIP_SMALL_CARGO_SHIP, 0, 0, 0);

        $this->assertEqualsWithDelta($base * 1.1, $fleets->getShipSpeed(Ships::SHIP_SMALL_CARGO_SHIP, 0, 0, 0, $trader), 1e-6);
        $this->assertEqualsWithDelta($base * 2 * 1.1, $fleets->getShipSpeed(Ships::SHIP_SMALL_CARGO_SHIP, 0, 0, 0, new ClassBonuses(PlayerClass::Collector, AllianceClass::Trader)), 1e-6);
        $this->assertSame($fleets->getShipSpeed(Ships::SHIP_CRUISER, 0, 3, 0), $fleets->getShipSpeed(Ships::SHIP_CRUISER, 0, 3, 0, $trader));

        $this->assertSame(11000, app(ProductionService::class)->maxStorable(0, $trader->storageFactor()));
        $this->assertSame(10000, app(ProductionService::class)->maxStorable(0, (new ClassBonuses())->storageFactor()));

        $mines = ['metal' => 1000.0, 'crystal' => 500.0, 'deuterium' => 200.0];
        $extras = app(ClassProductionService::class)->extras($mines, 3000, 0, 60, 10, $trader, false);
        $this->assertSame([50.0, 25.0, 10.0, 150.0], [$extras['metal'], $extras['crystal'], $extras['deuterium'], $extras['energyMax']]);

        $both = app(ClassProductionService::class)->extras($mines, 3000, 0, 60, 10, new ClassBonuses(PlayerClass::Collector, AllianceClass::Trader), false);
        $this->assertSame([300.0, 450.0], [$both['metal'], $both['energyMax']]);
    }

    public function testResearchersColonizeLargerPlanetsAndExploreFaster(): void
    {
        $researcher = new ClassBonuses(alliance: AllianceClass::Researcher);

        $this->assertSame(1.05, $researcher->planetSizeFactor());
        $this->assertEqualsWithDelta(1.1 * 1.05, (new ClassBonuses(PlayerClass::Discoverer, AllianceClass::Researcher))->planetSizeFactor(), 1e-9);
        $this->assertSame(1.1, $researcher->expeditionSpeedFactor());
        $this->assertSame(1.0, $researcher->allySpeedFactor());
        $this->assertSame(1.0, $researcher->storageFactor());
    }

    public function testWarriorsFightAndSpyBetterAndReachTheirAlliesFaster(): void
    {
        $warrior = new ClassBonuses(alliance: AllianceClass::Warrior);

        $this->assertSame(1, $warrior->combatTechBonus());
        $this->assertSame(3, (new ClassBonuses(PlayerClass::General, AllianceClass::Warrior))->combatTechBonus());
        $this->assertSame(1, $warrior->espionageBonus());
        $this->assertSame(0, (new ClassBonuses(alliance: AllianceClass::Trader))->espionageBonus());
        $this->assertSame(1.1, $warrior->allySpeedFactor());
        $this->assertSame(1.0, $warrior->expeditionSpeedFactor());
    }

    public function testTheBonusesComeFromTheUserRow(): void
    {
        $bonuses = PlayerClassService::bonusesFromRow(['player_class' => 2, 'alliance_class' => '3']);

        $this->assertSame(PlayerClass::General, $bonuses->player);
        $this->assertSame(AllianceClass::Warrior, $bonuses->alliance);
        $this->assertSame(AllianceClass::None, PlayerClassService::bonusesFromRow(['alliance_class' => null])->alliance);
        $this->assertSame('al_class_researcher', AllianceClass::Researcher->nameKey());
    }
}
