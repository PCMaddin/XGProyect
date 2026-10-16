<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

use App\Services\FormatService;
use App\Services\Game\Formulas\OfficerService;
use App\Core\Enumerators\MissionsEnumerator;
use App\Helpers\StringsHelper;
use App\Libraries\FleetsLib;
use App\Libraries\Functions;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Spy extends Missions
{
    private const ESPIONAGE_PROBE = 210;

    public function __construct(
        private FormatService $formatService,
        private OfficerService $officerService
    ) {
        parent::__construct();
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    public function spyMission(array $fleetRow): void
    {
        if (parent::canStartMission($fleetRow)) {
            $this->spy($fleetRow);

            return;
        }

        if ($fleetRow['fleet_mess'] == 1 && $fleetRow['fleet_end_time'] <= time()) {
            parent::restoreFleet($fleetRow);
            parent::removeFleet(self::int($fleetRow['fleet_id']));
        }
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    private function spy(array $fleetRow): void
    {
        parent::makeUpdate(
            self::int($fleetRow['fleet_end_galaxy']),
            self::int($fleetRow['fleet_end_system']),
            self::int($fleetRow['fleet_end_planet']),
            self::int($fleetRow['fleet_end_type'])
        );

        $currentData = $this->getSpyUserDataByCords([
            'coords' => [
                'galaxy' => $fleetRow['fleet_start_galaxy'],
                'system' => $fleetRow['fleet_start_system'],
                'planet' => $fleetRow['fleet_start_planet'],
                'type' => $fleetRow['fleet_start_type'],
            ],
        ]);

        $targetData = $this->getInquiredUserDataByCords([
            'coords' => [
                'galaxy' => $fleetRow['fleet_end_galaxy'],
                'system' => $fleetRow['fleet_end_system'],
                'planet' => $fleetRow['fleet_end_planet'],
                'type' => $fleetRow['fleet_end_type'],
            ],
        ]);

        $spyLevel = $this->espionageLevel($currentData);
        $targetLevel = $this->espionageLevel($targetData);

        foreach (FleetsLib::getFleetShipsArray(self::str($fleetRow['fleet_array'])) as $id => $probes) {
            if ($id != self::ESPIONAGE_PROBE) {
                continue;
            }

            $this->spyWithProbes($fleetRow, $currentData, $targetData, $probes, self::reportDepth($spyLevel, $targetLevel, $probes));
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function espionageLevel(array $user): int
    {
        return $this->officerService->getMaxEspionage(
            self::int($user['research_espionage_technology']),
            $this->officerService->isOfficerActive(self::int($user['premium_officier_technocrat']), time())
        );
    }

    /**
     * @param array<string, mixed> $fleetRow
     * @param array<string, mixed> $currentData
     * @param array<string, mixed> $targetData
     */
    private function spyWithProbes(array $fleetRow, array $currentData, array $targetData, int $probes, int $depth): void
    {
        // the report sections are cumulative: resources, + fleet, + defenses, + buildings, + research
        $sections = [];
        $fleetCount = 0;
        foreach ([0 => 'resources', 1 => 'fleet', 2 => 'defenses', 3 => 'buildings', 4 => 'research'] as $mode => $section) {
            $report = $this->generateSpyReport($targetData, $mode, self::str(__('game/spy.spy_report_' . $section)));
            $sections[] = $report['text'];

            if ($mode === 1) {
                $fleetCount = $report['count'];
            }
        }

        $targetForce = ($fleetCount * $probes) / 4;

        if ($targetForce > 100) {
            $targetForce = 100;
        }

        $targetChances = mt_rand(0, intval($targetForce));
        $spyerChances = mt_rand(0, 100);
        $arrivalTime = self::int($fleetRow['fleet_start_time']);
        $owner = self::int($fleetRow['fleet_owner']);

        if ($targetChances >= $spyerChances) {
            Functions::sendMessage(
                $owner,
                0,
                $arrivalTime,
                0,
                __('game/missions.mi_fleet_command'),
                sprintf(__('game/spy.spy_result_destroyed_title'), $this->formatService->prettyCoords(self::int($fleetRow['fleet_end_galaxy']), self::int($fleetRow['fleet_end_system']), self::int($fleetRow['fleet_end_planet']))),
                __('game/spy.spy_result_destroyed'),
                true
            );
        }

        $attackLink = '<center>';
        $attackLink .= '<a href="game.php?page=fleet1&galaxy=' . self::str($fleetRow['fleet_end_galaxy']) . '&system=' . self::str($fleetRow['fleet_end_system']) . '';
        $attackLink .= '&planet=' . self::str($fleetRow['fleet_end_planet']) . '&planettype=' . self::str($fleetRow['fleet_end_type']) . '';
        $attackLink .= '&target_mission=1';
        $attackLink .= ' ">' . self::str(__('game/missions.type_mission')[MissionsEnumerator::ATTACK]) . '';
        $attackLink .= '</a></center>';
        $messageEnd = '<center>' . sprintf(__('game/spy.spy_report_detection'), $targetChances) . '</center>';

        $spyMessage = $depth > 0
            ? implode('', array_slice($sections, 0, $depth)) . '<br>' . $attackLink . $messageEnd
            : '';

        Functions::sendMessage(
            $owner,
            0,
            $arrivalTime,
            0,
            __('game/missions.mi_fleet_command'),
            sprintf(__('game/spy.spy_report_title'), self::str($targetData['planet_name']), $this->formatService->prettyCoords(self::int($targetData['planet_galaxy']), self::int($targetData['planet_system']), self::int($targetData['planet_planet']))),
            $spyMessage,
            true
        );

        $this->sendReportToTarget($fleetRow, $currentData, $targetData, $targetChances);

        if ($targetChances >= $spyerChances) {
            $this->updateCrystalDebrisByPlanetId([
                'time' => time(),
                'crystal' => (0 + $probes * 300),
                'planet_id' => $targetData['planet_id'],
            ]);

            parent::removeFleet(self::int($fleetRow['fleet_id']));

            return;
        }

        parent::returnFleet(self::int($fleetRow['fleet_id']));
    }

    /**
     * How many report sections the probes reveal (resources, fleet, defenses,
     * buildings, research): the last section whose probe threshold is met.
     * A stronger spy needs fewer probes, a stronger target more (by the
     * square of the level difference).
     *
     * Bugfix: the original code overwrote both levels with fixed values
     * (100 / 0) right before comparing them, so the spy always counted as the
     * stronger side and the target's espionage technology never protected it.
     */
    private static function reportDepth(int $spyLevel, int $targetLevel, int $probes): int
    {
        $square = ($spyLevel - $targetLevel) ** 2;
        $shift = $targetLevel >= $spyLevel ? $square : -$square;
        $thresholds = [1, $shift + 2, $shift + 3, $shift + 5, $shift + 7];

        $depth = 0;
        foreach ($thresholds as $index => $threshold) {
            if ($threshold <= $probes) {
                $depth = $index + 1;
            }
        }

        return $depth;
    }

    /**
     * @param array<string, mixed> $targetData
     *
     * @return array{text: string, count: int|float}
     */
    private function generateSpyReport(array $targetData, int $mode, string $reportTitle): array
    {
        if ($mode === 0) {
            return ['text' => $this->resourcesReport($targetData, $reportTitle) . '</table>', 'count' => 0];
        }

        $ranges = match ($mode) {
            1 => [[200, 299]],
            2 => [[400, 499], [500, 599]],
            3 => [[1, 99]],
            4 => [[100, 199]],
            default => [],
        };

        $text = '<table width="440" cellspacing="1"><tr><td class="c" colspan="5">' . $reportTitle . '</td></tr>';
        $count = 0;

        foreach ($ranges as [$from, $to]) {
            $row = 0;

            for ($item = $from; $item <= $to; $item++) {
                if (!isset($this->resource[$item]) || !($targetData[$this->resource[$item]] > 0)) {
                    continue;
                }

                $name = $this->resource[$item];

                if ($row == 0) {
                    $text .= '<tr>';
                }

                $text .= '<td align=left>' . self::str(__('game/constructions.' . $name)) . '</td><td align=right>' . $this->formatService->prettyNumber(self::int($targetData[$name])) . '</td>';

                if ($row == 0) {
                    $text .= '<td>&nbsp;</td>';
                }

                $count += self::num($targetData[$name]);
                $row++;

                if ($row == 2) {
                    $text .= '</tr>';
                    $row = 0;
                }
            }

            // pad an incomplete last row
            if ($row != 0) {
                $text .= '<td>&nbsp;</td><td>&nbsp;</td></tr>';
            }
        }

        return ['text' => $text . '</table>', 'count' => $count];
    }

    /**
     * @param array<string, mixed> $targetData
     */
    private function resourcesReport(array $targetData, string $reportTitle): string
    {
        $text = '<table width="440"><tr><td class="c" colspan="5">';
        $text .= $reportTitle;
        $text .= '</td>';
        $text .= '</tr><tr>';
        $text .= '<td width=220>' . __('game/global.metal') . '</td><td width=220 align=right>' . $this->formatService->prettyNumber(self::int($targetData['planet_metal'])) . '</td><td>&nbsp;</td>';
        $text .= '<td width=220>' . __('game/global.crystal') . '</td></td><td width=220 align=right>' . $this->formatService->prettyNumber(self::int($targetData['planet_crystal'])) . '</td>';
        $text .= '</tr><tr>';
        $text .= '<td width=220>' . __('game/global.deuterium') . '</td><td width=220 align=right>' . $this->formatService->prettyNumber(self::int($targetData['planet_deuterium'])) . '</td><td>&nbsp;</td>';
        $text .= '<td width=220>' . __('game/global.energy') . '</td><td width=220 align=right>' . $this->formatService->prettyNumber(self::int($targetData['planet_energy_max'])) . '</td>';

        return $text . '</tr>';
    }

    /**
     * @param array<string, mixed> $fleet
     * @param array<string, mixed> $user
     * @param array<string, mixed> $target
     */
    private function sendReportToTarget(array $fleet, array $user, array $target, int $chances): void
    {
        Functions::sendMessage(
            self::int($target['planet_user_id']),
            0,
            self::int($fleet['fleet_start_time']),
            0,
            __('game/spy.spy_activity_from'),
            StringsHelper::parseReplacements(
                __('game/spy.spy_activity_title'),
                [
                    $target['planet_name'],
                    $this->formatService->prettyCoords(self::int($target['planet_galaxy']), self::int($target['planet_system']), self::int($target['planet_planet'])),
                ]
            ),
            StringsHelper::parseReplacements(
                __('game/spy.spy_activity_enemy_seen'),
                [
                    $user['planet_name'],
                    $this->formatService->prettyCoords(self::int($user['planet_galaxy']), self::int($user['planet_system']), self::int($user['planet_planet'])),
                    $user['name'],
                    $target['planet_name'],
                    $this->formatService->prettyCoords(self::int($target['planet_galaxy']), self::int($target['planet_system']), self::int($target['planet_planet'])),
                    $chances,
                ]
            ),
            true
        );
    }
}
