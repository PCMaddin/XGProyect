<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

use App\Libraries\Formulas;
use Xgp\App\Core\Enumerators\ShipsEnumerator as Ships;
use Xgp\App\Libraries\BattleEngine\Core\BattleReport;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Destroy extends CombatMission
{
    /**
     * The moon destruction attempt: 'none', 'moon' (the moon is destroyed) or
     * 'ds' (the death stars are destroyed), with the chances it was rolled against.
     *
     * @var array{attempted: bool, destroyed: string, moonChance: int, dsChance: int}
     */
    private array $destruction = [
        'attempted' => false,
        'destroyed' => 'none',
        'moonChance' => 0,
        'dsChance' => 0,
    ];

    /**
     * @param array<string, mixed> $fleetRow
     */
    public function destroyMission(array $fleetRow): void
    {
        $this->combatMission($fleetRow);
    }

    /**
     * @param array<string, mixed> $fleet
     */
    protected function canCompleteMission(array $fleet): bool
    {
        return $fleet['fleet_mess'] == 1 && parent::canCompleteMission($fleet);
    }

    protected function reportTitle(string $targetName, string $coords): string
    {
        return self::str(__('game/destroy.des_report_title')) . ' ' . $coords;
    }

    /**
     * Bugfix: when the attackers won but none of their death stars survived,
     * the death star count was read from a missing key, which raised an
     * "undefined array key" error. Without death stars there is now simply
     * no destruction attempt.
     *
     * @param array<string, mixed>  $targetPlanet
     * @param array<int, int|float> $ships
     */
    protected function onAttackerVictory(array $targetPlanet, array $ships): void
    {
        $deathStars = (int) ($ships[Ships::ship_deathstar] ?? 0);

        if ($deathStars > 0) {
            $this->tryToDestroyMoon(self::int($targetPlanet['planet_diameter']), $deathStars);
        }
    }

    /**
     * Legacy behaviour, kept as is (see MIGRATION.md, open points): when the
     * death stars are destroyed, the whole attacking fleet is removed.
     */
    protected function survivorsReturn(): bool
    {
        return $this->destruction['destroyed'] !== 'ds';
    }

    /**
     * When the moon was destroyed, fleets on their way to or from it fly to
     * the planet instead, and its owner is switched to the planet.
     *
     * @param array<string, mixed> $fleetRow
     * @param array<string, mixed> $targetPlanet
     * @param array<string, mixed> $targetUser
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function afterBattle(array $fleetRow, BattleReport $report, array $targetPlanet, array $targetUser): void
    {
        if ($this->destruction['destroyed'] !== 'moon') {
            return;
        }

        $coords = [
            'galaxy' => $fleetRow['fleet_end_galaxy'],
            'system' => $fleetRow['fleet_end_system'],
            'planet' => $fleetRow['fleet_end_planet'],
        ];

        $this->updateFleetsStatusToMakeThemReturn([
            'coords' => $coords,
            'time' => time(),
            'planet_id' => $targetPlanet['planet_id'],
        ]);

        if ($targetUser['current_planet'] == $targetPlanet['planet_id']) {
            $this->updateUserCurrentPlanetByCoordsAndUserId([
                'coords' => $coords,
                'planet_user_id' => $targetPlanet['planet_user_id'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    protected function reportAppendix(array $fleetRow, BattleReport $report): string
    {
        $lines = [
            sprintf(
                __('game/destroy.des_report_start'),
                self::str($fleetRow['planet_start_name']),
                $this->formatService->prettyCoords(
                    self::int($fleetRow['fleet_start_galaxy']),
                    self::int($fleetRow['fleet_start_system']),
                    self::int($fleetRow['fleet_start_planet'])
                ),
                self::str($fleetRow['planet_end_name']),
                $this->formatService->prettyCoords(
                    self::int($fleetRow['fleet_end_galaxy']),
                    self::int($fleetRow['fleet_end_system']),
                    self::int($fleetRow['fleet_end_planet'])
                )
            ),
            self::str($report->attackerHasWin()
                ? __('game/destroy.des_result_' . $this->destruction['destroyed'])
                : __('game/destroy.des_report_defender')),
            sprintf(__('game/destroy.des_moon_ds_chances'), $this->destruction['moonChance'], $this->destruction['dsChance']),
        ];

        return implode('<br>', $lines);
    }

    /**
     * One attempt per mission: first the moon, if it survives the death stars.
     */
    private function tryToDestroyMoon(int $diameter, int $deathStars): void
    {
        if ($this->destruction['attempted']) {
            return;
        }

        $this->destruction['attempted'] = true;
        $this->destruction['moonChance'] = Formulas::getMoonDestructionChance($diameter, $deathStars);

        if (mt_rand(0, 100) <= $this->destruction['moonChance']) {
            $this->destruction['destroyed'] = 'moon';

            return;
        }

        $this->destruction['dsChance'] = Formulas::getDeathStarsDestructionChance($diameter);

        if (mt_rand(0, 100) <= $this->destruction['dsChance']) {
            $this->destruction['destroyed'] = 'ds';
        }
    }
}
