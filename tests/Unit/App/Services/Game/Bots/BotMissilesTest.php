<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Bots;

use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\DefensesEnumerator as Defenses;
use App\Core\Enumerators\ResearchEnumerator as Research;
use App\Services\Game\Bots\BotMissiles;
use App\Services\Game\Bots\BotStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The missile silo is due up to level 4 once impulse drive allows missiles
 * and the metal mine is at 18 (the bot saves for it if a target is in
 * range, see BotCoordinatorMysqlTest); then the silo gets up to
 * eight interplanetary missiles, as far as budget and silo space allow.
 */
#[CoversClass(BotStrategy::class)]
#[CoversClass(BotMissiles::class)]
class BotMissilesTest extends TestCase
{
    private const DEVELOPED = [
        Buildings::BUILDING_METAL_MINE => 18, Buildings::BUILDING_CRYSTAL_MINE => 16, Buildings::BUILDING_DEUTERIUM_SINTETIZER => 14,
        Buildings::BUILDING_ROBOT_FACTORY => 10, Buildings::BUILDING_HANGAR => 12, Buildings::BUILDING_LABORATORY => 12,
        Buildings::BUILDING_SOLAR_PLANT => 20,
    ];

    public function testTheSiloIsDueOnceMissilesAreAllowed(): void
    {
        $strategy = $this->app->make(BotStrategy::class);
        $planet = [
            'planet_metal' => 900000, 'planet_metal_max' => 2000000, 'planet_crystal' => 900000, 'planet_crystal_max' => 2000000,
            'planet_deuterium' => 900000, 'planet_deuterium_max' => 2000000,
            'planet_metal_perhour' => 5000, 'planet_crystal_perhour' => 3000, 'planet_deuterium_perhour' => 1000,
            'planet_energy_max' => 5000, 'planet_energy_used' => -3000,
        ];
        $levels = self::DEVELOPED + [Research::RESEARCH_IMPULSE_DRIVE => 1];

        $this->assertSame(Buildings::BUILDING_MISSILE_SILO, $strategy->nextSiloStep($levels, $planet));
        // the usual plan does not have it
        $this->assertSame(Buildings::BUILDING_METAL_MINE, $strategy->nextBuilding($levels, $planet, true, true));
        // a storage first if level 4 (160,000 each) does not fit
        $small = ['planet_crystal_max' => 100000] + $planet;
        $this->assertSame(Buildings::BUILDING_CRYSTAL_STORE, $strategy->nextSiloStep($levels + [Buildings::BUILDING_MISSILE_SILO => 3], $small));
        // not without impulse drive, not beyond level 4, not before metal 18
        $this->assertNull($strategy->nextSiloStep(self::DEVELOPED, $planet));
        $this->assertNull($strategy->nextSiloStep($levels + [Buildings::BUILDING_MISSILE_SILO => 4], $planet));
        $this->assertNull($strategy->nextSiloStep([Buildings::BUILDING_METAL_MINE => 17] + $levels, $planet));
    }

    public function testTheSiloGetsAFewMissiles(): void
    {
        $missiles = $this->app->make(BotMissiles::class);
        $levels = self::DEVELOPED + [Research::RESEARCH_IMPULSE_DRIVE => 1, Buildings::BUILDING_MISSILE_SILO => 4];
        $rich = ['metal' => 1000000, 'crystal' => 1000000, 'deuterium' => 1000000];

        $this->assertSame([Defenses::DEFENSE_INTERPLANETARY_MISSILE, 8], $missiles->order($levels, [], $rich, 1000));
        $this->assertSame([Defenses::DEFENSE_INTERPLANETARY_MISSILE, 3], $missiles->order($levels, ['defense_interplanetary_missile' => 5], $rich, 1000));
        // 40 slots: 34 anti-ballistic missiles leave room for three
        $this->assertSame([Defenses::DEFENSE_INTERPLANETARY_MISSILE, 3], $missiles->order($levels, ['defense_anti-ballistic_missile' => 34], $rich, 1000));
        // two missiles' worth of deuterium
        $this->assertSame([Defenses::DEFENSE_INTERPLANETARY_MISSILE, 2], $missiles->order($levels, [], ['deuterium' => 25000] + $rich, 1000));
        $this->assertNull($missiles->order([Buildings::BUILDING_MISSILE_SILO => 3] + $levels, [], $rich, 1000));
        $this->assertNull($missiles->order($levels, ['defense_interplanetary_missile' => 8], $rich, 1000));
    }
}
