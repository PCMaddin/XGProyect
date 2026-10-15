<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

use App\Libraries\Functions;
use Xgp\App\Libraries\BattleEngine\Core\BattleReport;

/**
 * Stores the combat report of a combat mission and sends its link, colored
 * by the outcome, to every attacker and defender.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
trait SendsCombatReports
{
    /**
     * Store the combat report and send its link to every participant.
     *
     * @param array<string, mixed> $fleetRow
     */
    private function createNewReportAndSendIt(array $fleetRow, BattleReport $report, string $targetName): void
    {
        $attackerIds = $report->getAttackersId();
        $defenderIds = $report->getDefendersId();
        $reportContent = (string) $report;
        $rid = md5($reportContent) . time();

        $this->insertReport([
            'owners' => implode(',', array_merge($attackerIds, $defenderIds)),
            'rid' => $rid,
            'content' => $reportContent . $this->reportAppendix($fleetRow, $report),
            'time' => time(),
            'destroyed' => ($report->getLastRoundNumber() == 1) ? 1 : 0,
        ]);

        $title = $this->reportTitle(
            $targetName,
            $this->formatService->prettyCoords(
                self::int($fleetRow['fleet_end_galaxy']),
                self::int($fleetRow['fleet_end_system']),
                self::int($fleetRow['fleet_end_planet'])
            )
        );

        $attackerColor = match (true) {
            $report->attackerHasWin() => 'green',
            $report->isAdraw() => 'orange',
            default => 'red',
        };
        $defenderColor = match (true) {
            $report->attackerHasWin() => 'red',
            $report->isAdraw() => 'orange',
            default => 'green',
        };

        $this->sendReportLink($attackerIds, $this->buildReportLink($attackerColor, $rid, $title), $fleetRow);
        $this->sendReportLink($defenderIds, $this->buildReportLink($defenderColor, $rid, $title), $fleetRow);
    }

    /**
     * @param list<int>            $playerIds
     * @param array<string, mixed> $fleetRow
     */
    private function sendReportLink(array $playerIds, string $link, array $fleetRow): void
    {
        foreach ($playerIds as $playerId) {
            Functions::sendMessage(
                $playerId,
                0,
                self::int($fleetRow['fleet_start_time']),
                1,
                __('game/missions.mi_fleet_command'),
                $link,
                ''
            );
        }
    }

    private function buildReportLink(string $color, string $rid, string $title): string
    {
        $style = 'style="color:' . $color . ';"';
        $js = "OnClick=\'f(\"game.php?page=combatreport&report=" . $rid . "\", \"\");\'";

        return $this->formatService->link('', $title, '', $style . ' ' . $js);
    }
}
