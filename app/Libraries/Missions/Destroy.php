<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Services\Game\Formulas\FormulasService;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Libraries\BattleEngine\Core\BattleReport;

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
     * The surviving death stars try to destroy the moon. If they are
     * destroyed instead, only they are lost (and count as lost units); the
     * rest of the fleet flies home with its share of the loot.
     *
     * Bugfix: when no death star survived, their count was read from a
     * missing key ("undefined array key"); now there is no attempt. And a
     * destroyed attempt used to remove the whole attacking fleet, without
     * counting the lost ships.
     *
     * @param array<string, mixed>  $targetPlanet
     * @param array<int, int|float> $ships
     *
     * @return array<int, int|float>
     */
    protected function onAttackerVictory(int $playerId, array $targetPlanet, array $ships): array
    {
        $deathStars = (int) ($ships[Ships::SHIP_DEATHSTAR] ?? 0);

        if ($deathStars > 0) {
            $this->tryToDestroyMoon(self::int($targetPlanet['planet_diameter']), $deathStars);
        }

        if ($deathStars === 0 || $this->destruction['destroyed'] !== 'ds') {
            return $ships;
        }

        $this->updateLostShipsAndDefensePoints($playerId, [Ships::SHIP_DEATHSTAR => $ships[Ships::SHIP_DEATHSTAR]]);
        unset($ships[Ships::SHIP_DEATHSTAR]);

        return $ships;
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
                e(self::str($fleetRow['planet_start_name'])),
                $this->formatService->prettyCoords(
                    self::int($fleetRow['fleet_start_galaxy']),
                    self::int($fleetRow['fleet_start_system']),
                    self::int($fleetRow['fleet_start_planet'])
                ),
                e(self::str($fleetRow['planet_end_name'])),
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
            parent::reportAppendix($fleetRow, $report),
        ];

        return implode('<br>', array_filter($lines, fn (string $line): bool => $line !== ''));
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
        $this->destruction['moonChance'] = app(FormulasService::class)->getMoonDestructionChance($diameter, $deathStars);

        if (mt_rand(0, 100) <= $this->destruction['moonChance']) {
            $this->destruction['destroyed'] = 'moon';

            return;
        }

        $this->destruction['dsChance'] = app(FormulasService::class)->getDeathStarsDestructionChance($diameter);

        if (mt_rand(0, 100) <= $this->destruction['dsChance']) {
            $this->destruction['destroyed'] = 'ds';
        }
    }
}
