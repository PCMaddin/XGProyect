<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Libraries\BattleEngine\Core\Battle;
use App\Libraries\BattleEngine\Models\Defense;
use App\Libraries\BattleEngine\Models\Fleet;
use App\Libraries\BattleEngine\Models\HomeFleet;
use App\Libraries\BattleEngine\Models\Player;
use App\Libraries\BattleEngine\Models\PlayerGroup;
use App\Libraries\BattleEngine\Models\Ship;
use App\Libraries\BattleEngine\Models\ShipType;
use App\Libraries\Missions\MissionObjects;
use App\Services\Game\PlayerClassService;
use App\Support\Concerns\ReadsMixedValues;

/**
 * The battle simulator a bot consults before an attack, as players use one:
 * it runs the game's own battle engine (OPBE) on the forces the spy report
 * showed, with both sides' combat technologies, and changes nothing.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class BotBattleSimulator
{
    use ReadsMixedValues;

    private const SHIP_MIN_ID = 202;
    private const SHIP_MAX_ID = 219;

    /**
     * @param array<int, int>      $attackers  the attacking ships by id
     * @param array<string, mixed> $attacker   the attacker's row (research, class)
     * @param array<int, int>      $defenders  the target's ships and defences by id
     * @param array<string, mixed> $defender   the defender's row (research, class)
     *
     * @return array{won: bool, lost: float, debris: float} lost: the attacker's
     *     losses in metal and crystal; debris: the field the battle leaves
     */
    public function simulate(array $attackers, array $attacker, array $defenders, array $defender): array
    {
        if (array_sum($attackers) === 0) {
            return ['won' => false, 'lost' => 0.0, 'debris' => 0.0];
        }

        // the engine's constants
        require_once app_path('Libraries/BattleEngine/Utils/Includer.php');

        $attackingFleet = new Fleet(1);
        $homeFleet = new HomeFleet(0);

        foreach ($attackers as $id => $count) {
            if ($count > 0) {
                $attackingFleet->addShipType($this->unit($id, $count));
            }
        }

        foreach ($defenders as $id => $count) {
            if ($count > 0) {
                $homeFleet->addShipType($this->unit($id, $count));
            }
        }

        $battle = new Battle($this->group(self::asInt($attacker['id'] ?? 1), $attackingFleet, $attacker), $this->group(self::asInt($defender['id'] ?? 2), $homeFleet, $defender));
        $battle->startBattle();
        $report = $battle->getReport();

        return [
            'won' => $report->attackerHasWin(),
            'lost' => (float) $report->getTotalAttackersLostUnits(),
            'debris' => (float) array_sum($report->getDebris()),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function group(int $playerId, Fleet $fleet, array $row): PlayerGroup
    {
        $bonus = PlayerClassService::bonusesFromRow($row)->combatTechBonus();
        $player = new Player($playerId, [$fleet]);
        $player->setTech(
            self::asInt($row['research_weapons_technology'] ?? 0) + $bonus,
            self::asInt($row['research_shielding_technology'] ?? 0) + $bonus,
            self::asInt($row['research_armour_technology'] ?? 0) + $bonus
        );

        $group = new PlayerGroup();
        $group->addPlayer($player);

        return $group;
    }

    /**
     * A ship or defence stack wired like the combat missions do.
     */
    private function unit(int $id, int $count): ShipType
    {
        $specs = MissionObjects::combatSpecs()[$id] ?? [];
        $price = MissionObjects::prices()[$id] ?? [];
        $rapidFire = [];

        foreach (is_array($specs['sd'] ?? null) ? $specs['sd'] : [] as $target => $shots) {
            $rapidFire[(int) $target] = self::asInt($shots);
        }

        $shield = self::asFloat($specs['shield'] ?? 0);
        $power = self::asFloat($specs['attack'] ?? 0);
        $cost = [self::asInt($price['metal'] ?? 0), self::asInt($price['crystal'] ?? 0)];

        return $id >= self::SHIP_MIN_ID && $id <= self::SHIP_MAX_ID
            ? new Ship($id, $count, $rapidFire, $shield, $cost, $power)
            : new Defense($id, $count, $rapidFire, $shield, $cost, $power);
    }
}
