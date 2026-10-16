<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

use App\Libraries\PlanetLib;
use Xgp\App\Libraries\BattleEngine\Core\BattleReport;

class Attack extends CombatMission
{
    /**
     * @param array<string, mixed> $fleetRow
     */
    public function attackMission(array $fleetRow): void
    {
        $this->combatMission($fleetRow);
    }

    protected function reportTitle(string $targetName, string $coords): string
    {
        return sprintf(__('game/attack.at_report_title'), $targetName, $coords);
    }

    /**
     * The debris of the battle may form a moon.
     *
     * @param array<string, mixed> $fleetRow
     * @param array<string, mixed> $targetPlanet
     * @param array<string, mixed> $targetUser
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function afterBattle(array $fleetRow, BattleReport $report, array $targetPlanet, array $targetUser): void
    {
        $moon = $report->tryMoon();

        if (!is_array($moon)) {
            return;
        }

        $this->createMoonIfMissing(
            self::int($fleetRow['fleet_end_galaxy']),
            self::int($fleetRow['fleet_end_system']),
            self::int($fleetRow['fleet_end_planet']),
            self::int($targetUser['id']),
            self::int($moon['size'] ?? 0)
        );
    }

    /**
     * Bugfix: the lookup returns an empty row when there is no moon yet, and
     * reading its `planet_id` raised an "undefined array key" error. Every
     * battle that should have created a moon crashed right before the combat
     * report was stored and sent.
     *
     * The moon gets the diameter rolled by the battle (bigger with more
     * debris); it used to be ignored for a random 2000-6000. Like every new
     * moon it starts with one field (`fields` of the battle event is the
     * planet field formula and does not apply to moons).
     */
    private function createMoonIfMissing(int $galaxy, int $system, int $planet, int $owner, int $diameter): void
    {
        $moon = $this->getMoonIdByCoords([
            'coords' => [
                'galaxy' => $galaxy,
                'system' => $system,
                'planet' => $planet,
            ],
        ]);

        if (isset($moon['planet_id'])) {
            return;
        }

        (new PlanetLib())->setNewMoon($galaxy, $system, $planet, $owner, '', 0, $diameter);
    }
}
