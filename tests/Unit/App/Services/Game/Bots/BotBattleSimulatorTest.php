<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Bots;

use App\Core\Enumerators\DefensesEnumerator as Defenses;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Services\Game\Bots\BotBattleSimulator;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The bots' battle simulator runs the game's own battle engine: cruisers
 * crush rocket launchers (rapid fire), a few light fighters lose against a
 * defended planet, and the debris is 30 % of what was destroyed.
 */
#[CoversClass(BotBattleSimulator::class)]
class BotBattleSimulatorTest extends TestCase
{
    public function testCruisersCrushRocketLaunchers(): void
    {
        $battle = app(BotBattleSimulator::class)->simulate([Ships::SHIP_CRUISER => 40], ['id' => 1], [Defenses::DEFENSE_ROCKET_LAUNCHER => 20], ['id' => 2]);

        $this->assertTrue($battle['won']);
        $this->assertSame(0.0, $battle['lost']);
        // 20 rocket launchers of 2,000 metal, 30 % of it
        $this->assertEqualsWithDelta(12000.0, $battle['debris'], 0.1);
    }

    public function testAFewFightersLoseAgainstADefendedPlanet(): void
    {
        $battle = app(BotBattleSimulator::class)->simulate(
            [Ships::SHIP_LIGHT_FIGHTER => 30],
            ['id' => 1],
            [Defenses::DEFENSE_ROCKET_LAUNCHER => 150, Defenses::DEFENSE_LIGHT_LASER => 50],
            ['id' => 2, 'research_weapons_technology' => 5]
        );

        $this->assertFalse($battle['won']);
        // all 30 fighters of 4,000 metal and crystal
        $this->assertEqualsWithDelta(120000.0, $battle['lost'], 0.1);
    }

    public function testNoShipsNoBattle(): void
    {
        $this->assertSame(['won' => false, 'lost' => 0.0, 'debris' => 0.0], app(BotBattleSimulator::class)->simulate([], [], [Defenses::DEFENSE_ROCKET_LAUNCHER => 1], []));
    }
}
