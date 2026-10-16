<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Support\FleetShips;
use App\Libraries\BattleEngine\Core\Battle;
use App\Libraries\BattleEngine\Core\BattleReport;
use App\Libraries\BattleEngine\Models\Fleet;
use App\Libraries\BattleEngine\Models\Player;
use App\Libraries\BattleEngine\Models\PlayerGroup;
use App\Libraries\Functions;
use App\Services\FormatService;
use App\Services\Game\Formulas\ExpeditionService;

/**
 * The battle of an expedition that meets pirates or aliens.
 *
 * The enemy (ExpeditionService) attacks the expedition fleet in deep space:
 * no debris, no loot. What survives flies home, the losses count for the
 * statistics, and the player gets the combat report.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class ExpeditionBattle extends Missions
{
    use BuildsCombatForces;

    protected const SHIP_MIN_ID = 202;
    protected const SHIP_MAX_ID = 215;
    protected const DEFENSE_MIN_ID = 401;
    protected const DEFENSE_MAX_ID = 408;
    protected const PLANET_FORCES = 0;

    /** the enemy is no player of the game */
    private const ENEMY_ID = 0;

    public function __construct(
        private ExpeditionService $expeditionService,
        private FormatService $formatService,
    ) {
        parent::__construct();
    }

    /**
     * @param array<string, mixed> $fleetRow the arriving expedition
     * @param string               $enemy    'pirates' or 'aliens'
     */
    public function fight(array $fleetRow, string $enemy): void
    {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'BattleEngine' . DIRECTORY_SEPARATOR . 'Utils' . DIRECTORY_SEPARATOR . 'Includer.php';

        AttackLang::activate($this->resource);

        $playerId = self::int($fleetRow['fleet_owner']);
        $fleetId = self::int($fleetRow['fleet_id']);
        $ships = FleetShips::decode(self::str($fleetRow['fleet_array']));
        $technologies = $this->getTechnologiesByUserId($playerId);

        $expedition = $this->buildFleet($fleetRow);
        $player = new Player($playerId, [$expedition]);
        $this->equipPlayer($player, $technologies, $fleetRow, 'fleet_end_');

        $battle = new Battle(
            $this->group($this->enemy($enemy, $ships, $technologies, $fleetRow)),
            $this->group($player)
        );
        $battle->startBattle();
        $report = $battle->getReport();

        $survivors = CombatSurvivors::ships(
            $expedition,
            CombatSurvivors::fleet($report->getAfterBattleDefenders(), $playerId, $fleetId)
        );

        $this->recordLosses($playerId, $ships, $survivors);
        $this->sendReport($fleetRow, $report, (string) __('game/expedition.exp_' . $enemy . '_name'));

        if ($survivors === []) {
            $this->removeFleet($fleetId);

            return;
        }

        $this->updateFleetShipsById([
            'ships' => FleetShips::encode($survivors),
            'amount' => array_sum($survivors),
            'fleet_id' => $fleetId,
        ]);
        $this->returnFleet($fleetId);
    }

    /**
     * @param array<int, int|float> $ships        the expedition's ships
     * @param array<string, mixed>  $technologies the player's
     * @param array<string, mixed>  $fleetRow
     */
    private function enemy(string $enemy, array $ships, array $technologies, array $fleetRow): Player
    {
        $fleet = new Fleet(0);

        foreach ($this->expeditionService->getEnemyFleet($enemy, $this->expeditionService->getEnemySize(), $ships) as $id => $count) {
            $fleet->addShipType($this->getShipType($id, $count));
        }

        $bonus = $this->expeditionService->getEnemyTechnologyBonus($enemy);
        $level = fn (string $technology): int => max(0, self::int($technologies[$technology] ?? 0) + $bonus);

        $player = new Player(self::ENEMY_ID, [$fleet]);
        $player->setTech(
            $level('research_weapons_technology'),
            $level('research_shielding_technology'),
            $level('research_armour_technology')
        );
        $player->setCoords(
            self::int($fleetRow['fleet_end_galaxy']),
            self::int($fleetRow['fleet_end_system']),
            self::int($fleetRow['fleet_end_planet'])
        );
        $player->setName((string) __('game/expedition.exp_' . $enemy . '_name'));

        return $player;
    }

    private function group(Player $player): PlayerGroup
    {
        $group = new PlayerGroup();
        $group->addPlayer($player);

        return $group;
    }

    /**
     * @param array<int, int|float> $before
     * @param array<int, int|float> $after
     */
    private function recordLosses(int $playerId, array $before, array $after): void
    {
        $lost = [];

        foreach ($before as $id => $count) {
            $missing = $count - ($after[$id] ?? 0);

            if ($missing > 0) {
                $lost[$id] = $missing;
            }
        }

        if ($lost !== []) {
            $this->updateLostShipsAndDefensePoints($playerId, $lost);
        }
    }

    /**
     * Store the combat report and send its link to the player (the enemy is
     * no player).
     *
     * @param array<string, mixed> $fleetRow
     */
    private function sendReport(array $fleetRow, BattleReport $report, string $enemyName): void
    {
        $playerId = self::int($fleetRow['fleet_owner']);
        $content = (string) $report;
        $rid = md5($content) . time();

        $this->insertReport([
            'owners' => (string) $playerId,
            'rid' => $rid,
            'content' => $content,
            'time' => time(),
            'destroyed' => ($report->getLastRoundNumber() == 1) ? 1 : 0,
        ]);

        // the player defends: green when the enemy did not win
        $color = match (true) {
            $report->isAdraw() => 'orange',
            $report->attackerHasWin() => 'red',
            default => 'green',
        };
        $title = sprintf(
            (string) __('game/attack.at_report_title'),
            $enemyName,
            $this->formatService->prettyCoords(
                self::int($fleetRow['fleet_end_galaxy']),
                self::int($fleetRow['fleet_end_system']),
                self::int($fleetRow['fleet_end_planet'])
            )
        );
        $link = $this->formatService->link(
            '',
            $title,
            '',
            'style="color:' . $color . ';" ' . "OnClick=\'f(\"game.php?page=combatreport&report=" . $rid . "\", \"\");\'"
        );

        Functions::sendMessage(
            $playerId,
            0,
            self::int($fleetRow['fleet_end_stay']),
            1,
            __('game/missions.mi_fleet_command'),
            $link,
            ''
        );
    }
}
