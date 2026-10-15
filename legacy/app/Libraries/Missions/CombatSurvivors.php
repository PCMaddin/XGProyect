<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

use Xgp\App\Libraries\BattleEngine\Models\Fleet;
use Xgp\App\Libraries\BattleEngine\Models\PlayerGroup;

/**
 * What is left of a fleet after the battle.
 */
final class CombatSurvivors
{
    /**
     * The fleet after the battle, null when it was destroyed.
     */
    public static function fleet(PlayerGroup $afterBattle, int $playerId, int $fleetId): ?Fleet
    {
        $player = $afterBattle->getPlayer($playerId);

        if ($player === false || !$player->existFleet($fleetId)) {
            return null;
        }

        return $player->getFleet($fleetId);
    }

    /**
     * The ship counts after the battle of the ship types the fleet started
     * with (types without survivors are left out).
     *
     * @return array<int, int|float>
     */
    public static function ships(Fleet $fleet, ?Fleet $survivingFleet): array
    {
        $ships = [];

        if ($survivingFleet === null) {
            return $ships;
        }

        foreach (array_keys($fleet->getIterator()) as $shipId) {
            if ($survivingFleet->existShipType($shipId)) {
                $ships[$shipId] = $survivingFleet->getShipType($shipId)->getCount();
            }
        }

        return $ships;
    }
}
