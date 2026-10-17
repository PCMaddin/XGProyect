<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\BattleEngine;

use App\Libraries\BattleEngine\Core\Battle;
use App\Libraries\BattleEngine\Core\BattleLosses;
use App\Libraries\BattleEngine\Models\Defense;
use App\Libraries\BattleEngine\Models\Fleet;
use App\Libraries\BattleEngine\Models\Player;
use App\Libraries\BattleEngine\Models\PlayerGroup;
use App\Libraries\BattleEngine\Models\Ship;
use App\Libraries\BattleEngine\Utils\LangManager;
use App\Libraries\Missions\AttackLang;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The engineer halves the losses of defenses: of the destroyed ones 85 %
 * come back instead of 70 %. Ships are not repaired either way.
 */
#[CoversClass(BattleLosses::class)]
#[CoversClass(Player::class)]
class EngineerDefenseLossTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 5) . '/app/Libraries/BattleEngine/Utils/Includer.php';
    }

    /**
     * @return array<string, array{0: float, 1: int}>
     */
    public static function engineers(): array
    {
        return ['without engineer' => [1.0, 70], 'with engineer' => [0.5, 85]];
    }

    #[DataProvider('engineers')]
    public function testDestroyedDefensesComeBack(float $lossFactor, int $repaired): void
    {
        mt_srand(42);
        LangManager::getInstance()->setImplementation(new AttackLang([207 => 'ship_battleship', 401 => 'defense_rocket_launcher', 204 => 'ship_light_fighter']));

        $defender = new Player(7, [new Fleet(0, [new Defense(401, 100, [], 20, [2000, 0], 80), new Ship(204, 10, [], 10, [3000, 1000], 50)])]);
        $defender->setDefenseLossFactor($lossFactor);

        $battle = new Battle(
            new PlayerGroup([new Player(1, [new Fleet(2, [new Ship(207, 500, [], 200, [45000, 15000], 1000)])], 10, 10, 10)]),
            new PlayerGroup([$defender])
        );
        $battle->startBattle();
        $report = $battle->getReport();

        $repairedGroup = $report->getDefendersRepaired();
        $repairedPlayer = $repairedGroup->getPlayer(7);
        $this->assertNotFalse($repairedPlayer);
        $fleet = $repairedPlayer->getFleet(0);
        $this->assertSame($repaired, (int) $fleet->getShipType(401)->getCount());
        $this->assertFalse($fleet->existShipType(204) && $fleet->getShipType(204)->getCount() > 0);
    }
}
