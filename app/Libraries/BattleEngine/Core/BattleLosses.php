<?php

declare(strict_types=1);

namespace App\Libraries\BattleEngine\Core;

use Exception;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use App\Libraries\BattleEngine\Models\PlayerGroup;

/**
 * The losses of one side of a battle: lost and repaired units, the forces
 * left after the battle and the debris (part of OPBE's BattleReport).
 */
final class BattleLosses
{
    /**
     * The value (metal, crystal) of the lost units by player, fleet, role and
     * unit type; with $repair, the repaired units do not count as lost.
     *
     * @return array<int, array<int, array<string, array<int, array{0: int|float, 1: int|float}>>>>
     */
    public function lostUnits(PlayerGroup $playersBefore, PlayerGroup $playersAfter, bool $repair): array
    {
        $lostShips = $this->lostShips($playersBefore, $playersAfter);
        $defRepaired = $this->repaired($playersBefore, $playersAfter);
        $return = [];
        foreach ($lostShips->getIterator() as $idPlayer => $player) {
            foreach ($player->getIterator() as $idFleet => $fleet) {
                foreach ($fleet->getIterator() as $idShipType => $shipType) {
                    $cost = $shipType->getCost();
                    $repairedAmount = 0;
                    $repairedPlayer = $repair ? $defRepaired->getPlayer($idPlayer) : false;
                    if ($repairedPlayer !== false && $repairedPlayer->existFleet($idFleet) && $repairedPlayer->getFleet($idFleet)->existShipType($idShipType)) {
                        $repairedAmount = $repairedPlayer->getFleet($idFleet)->getShipType($idShipType)->getCount();
                    }
                    $count = $shipType->getCount() - $repairedAmount;
                    if ($count < 0) {
                        throw new Exception('Count negative');
                    }
                    if ($count > 0) {
                        $return[$idPlayer][$idFleet][self::role($shipType)][$idShipType] = [$cost[0] * $count, $cost[1] * $count];
                    }
                }
            }
        }
        return $return;
    }

    /**
     * The sum of all values of a lost units structure.
     *
     * @param array<int|string, mixed> $lostUnits
     */
    public static function total(array $lostUnits): int | float
    {
        $sum = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator($lostUnits)) as $value) {
            $sum += is_numeric($value) ? $value : 0;
        }
        return $sum;
    }

    /**
     * The metal and crystal of lost units (as lostUnits() returns them).
     *
     * @param array<int|string, array<int|string, array<string, array<int|string, array{0: int|float, 1: int|float}>>>> $lostUnits
     *
     * @return array{0: int|float, 1: int|float}
     */
    public function units(array $lostUnits): array
    {
        $metal = 0;
        $crystal = 0;

        foreach ($lostUnits as $player) {
            foreach ($player as $fleet) {
                foreach ($fleet as $values) {
                    foreach ($values as $lost) {
                        $metal += $lost[0];
                        $crystal += $lost[1];
                    }
                }
            }
        }

        return [$metal, $crystal];
    }

    /**
     * The debris (metal, crystal) of lost units: SHIP_DEBRIS_FACTOR /
     * DEFENSE_DEBRIS_FACTOR of their value.
     *
     * @param array<int, array<int, array<string, array<int, array{0: int|float, 1: int|float}>>>> $lostUnits
     *
     * @return array{0: int|float, 1: int|float}
     */
    public function debris(array $lostUnits): array
    {
        $sendMetal = 0;
        $sendCrystal = 0;
        foreach ($lostUnits as $player) {
            foreach ($player as $fleet) {
                foreach ($fleet as $role => $values) {
                    $metal = 0;
                    $crystal = 0;
                    foreach ($values as $lost) {
                        $metal += $lost[0];
                        $crystal += $lost[1];
                    }
                    $factorRaw = constant(strtoupper($role) . '_DEBRIS_FACTOR');
                    $factor = is_numeric($factorRaw) ? (float) $factorRaw : 0.0;
                    $sendMetal += $metal * $factor;
                    $sendCrystal += $crystal * $factor;
                }
            }
        }
        return [$sendMetal, $sendCrystal];
    }

    /**
     * The lost units that are repaired after the battle.
     */
    public function repaired(PlayerGroup $playersBefore, PlayerGroup $playersAfter): PlayerGroup
    {
        $lostShips = $this->lostShips($playersBefore, $playersAfter);
        foreach ($lostShips->getIterator() as $idPlayer => $player) {
            foreach ($player->getIterator() as $idFleet => $fleet) {
                foreach ($fleet->getIterator() as $idShipType => $shipType) {
                    $lostShips->decrement($idPlayer, $idFleet, $idShipType, (int) round($shipType->getCount() * (1 - $shipType->getRepairProb())));
                }
            }
        }
        return $lostShips;
    }

    /**
     * The units of the players before the battle that are missing after it.
     */
    public function lostShips(PlayerGroup $playersBefore, PlayerGroup $playersAfter): PlayerGroup
    {
        $playersBeforeClone = $playersBefore->cloneMe();

        foreach ($playersAfter->getIterator() as $idPlayer => $playerAfter) {
            foreach ($playerAfter->getIterator() as $idFleet => $fleet) {
                foreach ($fleet->getIterator() as $idShipType => $shipType) {
                    $playersBeforeClone->decrement($idPlayer, $idFleet, $idShipType, $shipType->getCount());
                }
            }
        }
        return $playersBeforeClone;
    }

    /**
     * The players at the end of the battle plus their repaired units.
     */
    public function afterBattle(PlayerGroup $players, PlayerGroup $playersRepaired): PlayerGroup
    {
        foreach ($playersRepaired->getIterator() as $idPlayer => $playerRepaired) {
            $endPlayer = $players->getPlayer($idPlayer);

            // the player is completely destroyed
            if ($endPlayer === false) {
                $players->addPlayer($playerRepaired);
                continue;
            }

            foreach ($playerRepaired->getIterator() as $idFleet => $fleetRepaired) {
                if (!$endPlayer->existFleet($idFleet)) {
                    $endPlayer->addFleet($fleetRepaired);
                    continue;
                }
                $endFleet = $endPlayer->getFleet($idFleet);
                foreach ($fleetRepaired->getIterator() as $shipTypeRepaired) {
                    $endFleet->addShipType($shipTypeRepaired);
                }
            }
        }
        return $players;
    }

    private static function role(object $shipType): string
    {
        $className = get_class($shipType);
        $separatorPosition = strrpos($className, '\\');

        return $separatorPosition === false ? $className : substr($className, $separatorPosition + 1);
    }
}
