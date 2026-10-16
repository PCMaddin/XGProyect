<?php

declare(strict_types=1);

namespace Tests\Unit\App\Support;

use App\Libraries\Missions\CombatMission;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\Formulas\OfficerService;
use App\Support\ClassBonuses;
use App\Support\OfficerBonuses;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The officers' bonuses: the admiral's expedition, the commanding staff
 * of all five officers, the plunder priority.
 */
#[CoversClass(OfficerBonuses::class)]
class OfficerBonusesTest extends TestCase
{
    public function testTheOfficersComeFromTheExpiryTimes(): void
    {
        $officers = OfficerBonuses::fromRow(['premium_officier_admiral' => 2000, 'premium_officier_engineer' => 999, 'premium_officier_geologist' => '1500'], 1000);

        $this->assertTrue($officers->admiral);
        $this->assertFalse($officers->engineer);
        $this->assertTrue($officers->geologist);
        $this->assertFalse($officers->commandingStaff());
    }

    public function testEachOfficerAndTheCommandingStaff(): void
    {
        $none = new OfficerBonuses();
        $admiral = new OfficerBonuses(admiral: true);
        $staff = new OfficerBonuses(true, true, true, true, true);
        $fleets = app(FleetsService::class);

        // admiral: +2 fleet slots, +1 expedition; the staff one more slot
        $this->assertSame([4, 6, 7], [$fleets->getMaxFleets(3, $none), $fleets->getMaxFleets(3, $admiral), $fleets->getMaxFleets(3, $staff)]);
        $this->assertSame([3, 4], [$fleets->getMaxExpeditions(9), $fleets->getMaxExpeditions(9, new ClassBonuses(), $admiral)]);
        $this->assertSame(6, $fleets->getMaxFleets(3, true));

        // geologist and engineer +10 %, with the staff +12 %
        $this->assertSame([0.1, 0.1], [(new OfficerBonuses(geologist: true))->mineBonus(), (new OfficerBonuses(engineer: true))->energyBonus()]);
        $this->assertEqualsWithDelta(0.12, $staff->mineBonus(), 1e-9);
        $this->assertEqualsWithDelta(0.12, $staff->energyBonus(), 1e-9);

        // technocrat +2 espionage, with the staff +3
        $officer = new OfficerService();
        $this->assertSame([5, 7, 8], [
            $officer->getMaxEspionage(5, $none),
            $officer->getMaxEspionage(5, new OfficerBonuses(technocrat: true)),
            $officer->getMaxEspionage(5, $staff),
        ]);

        // the engineer halves the losses of defenses
        $this->assertSame([1.0, 0.5], [$none->defenseLossFactor(), (new OfficerBonuses(engineer: true))->defenseLossFactor()]);
    }

    public function testThePlunderPriorityLoadsInItsOrder(): void
    {
        $plunder = new ReflectionMethod(CombatMission::class, 'plunderInOrder');
        $available = ['metal' => 10000, 'crystal' => 8000, 'deuterium' => 6000];

        // half of each can be taken: 5.000 / 4.000 / 3.000 into 8.000 cargo
        $this->assertEquals(['metal' => 1000, 'crystal' => 4000, 'deuterium' => 3000], $plunder->invoke(null, 8000, $available, 0.5, 'dcm'));
        $this->assertEquals(['metal' => 5000, 'crystal' => 3000, 'deuterium' => 0], $plunder->invoke(null, 8000, $available, 0.5, 'mcd'));
        $this->assertTrue(CombatMission::isLootPriority('cdm'));
        $this->assertFalse(CombatMission::isLootPriority('mmm'));
    }
}
