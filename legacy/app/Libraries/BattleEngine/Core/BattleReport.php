<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\BattleEngine\Core;

use Exception;
use Xgp\App\Libraries\BattleEngine\Models\PlayerGroup;
use Xgp\App\Libraries\BattleEngine\Utils\Events;
use Xgp\App\Libraries\BattleEngine\Utils\Math;

/**
 *  OPBE
 *  Copyright (C) 2013  Jstar
 *
 * This file is part of OPBE.
 *
 * OPBE is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OPBE is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with OPBE.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package OPBE
 *
 * @author Jstar <frascafresca@gmail.com>
 * @copyright 2013 Jstar <frascafresca@gmail.com>
 * @license http://www.gnu.org/licenses/ GNU AGPLv3 License
 *
 * @version beta(26-10-2013)
 *
 * @link https://github.com/jstar88/opbe
 */
class BattleReport
{
    /** @var array<int, Round> */
    private array $rounds;
    private int $roundsCount;
    private mixed $steal;
    private mixed $moonEvent = null;

    public string $css = '../../';

    public function __construct()
    {
        $this->rounds = [];
        $this->roundsCount = 0;
        $this->steal = 0;
    }

    /**
     * BattleReport::addRound()
     * Store a round
     *
     * @param Round $round
     */
    public function addRound(Round $round): void
    {
        if (ONLY_FIRST_AND_LAST_ROUND && $this->roundsCount == 2) {
            $this->rounds[1] = $round;
            return;
        }
        $this->rounds[$this->roundsCount++] = $round;
    }

    /**
     * BattleReport::getRound()
     * Retrive a round.
     *
     * @param string|int $number "START" to get the first round, "END" to get the last one, an integer(from zero) to get the corrispective round
     */
    public function getRound(string | int $number): Round
    {
        if ($number === 'END') {
            return $this->rounds[$this->roundsCount - 1];
        } elseif ($number === 'START') {
            return $this->rounds[0];
        } elseif (intval($number) < 0 || intval($number) > $this->getLastRoundNumber()) {
            throw new Exception('Invalid round number');
        } else {
            return $this->rounds[intval($number)];
        }
    }

    /**
     * Alias of getRound(). Get the round after it was processed
     */
    private function getResultRound(string | int $number): Round
    {
        return $this->getRound($number);
    }

    /**
     * Get the round before it was processed.
     */
    private function getPresentationRound(string | int $number): Round
    {
        if ($number !== 'START' && $number !== 'END') {
            $number = (int) $number - 1;
        }

        return $this->getRound($number);
    }

    /**
     * Set the result of a battle
     *
     * @param int|null $att (BATTLE_WIN ,BATTLE_LOSE, BATTLE_DRAW)
     * @param int|null $def (BATTLE_WIN ,BATTLE_LOSE, BATTLE_DRAW)
     */
    public function setBattleResult(?int $att, ?int $def): void
    {
        $this->getRound('END')->getAfterBattleAttackers()->battleResult = $att;
        $this->getRound('END')->getAfterBattleDefenders()->battleResult = $def;
    }

    /**
     * BattleReport::attackerHasWin()
     * Check if attackers won the battle
     *
     * @return boolean
     */
    public function attackerHasWin(): bool
    {
        return $this->getRound('END')->getAfterBattleAttackers()->battleResult === BATTLE_WIN;
    }

    /**
     * BattleReport::defenderHasWin()
     * Check if defenders won the battle
     *
     * @return boolean
     */
    public function defenderHasWin(): bool
    {
        return $this->getRound('END')->getAfterBattleDefenders()->battleResult === BATTLE_WIN;
    }

    /**
     * BattleReport::isAdraw()
     * Check if the battle ended with a draw
     *
     * @return boolean
     */
    public function isAdraw(): bool
    {
        return $this->getRound('END')->getAfterBattleAttackers()->battleResult === BATTLE_DRAW;
    }

    public function getPresentationAttackersFleetOnRound(string | int $number): PlayerGroup
    {
        return $this->getPresentationRound($number)->getAfterBattleAttackers();
    }

    public function getPresentationDefendersFleetOnRound(string | int $number): PlayerGroup
    {
        return $this->getPresentationRound($number)->getAfterBattleDefenders();
    }

    public function getResultAttackersFleetOnRound(string | int $number): PlayerGroup
    {
        return $this->getResultRound($number)->getAfterBattleAttackers();
    }

    public function getResultDefendersFleetOnRound(string | int $number): PlayerGroup
    {
        return $this->getResultRound($number)->getAfterBattleDefenders();
    }

    //-------------------  Lost units functions -------------------
    public function getTotalAttackersLostUnits(): int | float
    {
        return Math::recursive_sum($this->getAttackersLostUnits());
    }

    public function getTotalDefendersLostUnits(): int | float
    {
        return Math::recursive_sum($this->getDefendersLostUnits());
    }

    /**
     * @return array<int, array<int, array<string, array<int, array{0: int|float, 1: int|float}>>>>
     */
    public function getAttackersLostUnits(bool $repair = true): array
    {
        $attackersBefore = $this->getRound('START')->getAfterBattleAttackers();
        $attackersAfter = $this->getRound('END')->getAfterBattleAttackers();
        return $this->getPlayersLostUnits($attackersBefore, $attackersAfter, $repair);
    }

    /**
     * @return array<int, array<int, array<string, array<int, array{0: int|float, 1: int|float}>>>>
     */
    public function getDefendersLostUnits(bool $repair = true): array
    {
        $defendersBefore = $this->getRound('START')->getAfterBattleDefenders();
        $defendersAfter = $this->getRound('END')->getAfterBattleDefenders();
        return $this->getPlayersLostUnits($defendersBefore, $defendersAfter, $repair);
    }

    /**
     * @return array<int, array<int, array<string, array<int, array{0: int|float, 1: int|float}>>>>
     */
    private function getPlayersLostUnits(PlayerGroup $playersBefore, PlayerGroup $playersAfter, bool $repair = true): array
    {
        $lostShips = $this->getPlayersLostShips($playersBefore, $playersAfter);
        $defRepaired = $this->getPlayerRepaired($playersBefore, $playersAfter);
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
                    if ($count > 0) {
                        $return[$idPlayer][$idFleet][$this->getShipTypeRole($shipType)][$idShipType] = [$cost[0] * $count, $cost[1] * $count];
                    } elseif ($count < 0) {
                        throw new Exception('Count negative');
                    }
                }
            }
        }
        return $return;
    }

    //--------------------------------------------------------------
    public function tryMoon(): mixed
    {
        $prob = $this->getMoonProb();

        $this->moonEvent = Math::tryEvent($prob, [Events::class, 'event_moon'], $prob);

        return $this->moonEvent;
    }

    public function getMoonEvent(): mixed
    {
        return $this->moonEvent;
    }

    public function getMoonProb(): int
    {
        return (int) min(floor(array_sum($this->getDebris()) / MOON_UNIT_PROB), MAX_MOON_PROB);
    }

    /**
     * @return array{0: int|float, 1: int|float}
     */
    public function getAttackerDebris(): array
    {
        $sendMetal = 0;
        $sendCrystal = 0;
        foreach ($this->getAttackersLostUnits(!REPAIRED_DO_DEBRIS) as $idPlayer => $player) {
            foreach ($player as $idFleet => $fleet) {
                foreach ($fleet as $role => $values) {
                    $metal = 0;
                    $crystal = 0;
                    foreach ($values as $idShipType => $lost) {
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
     * @return array{0: int|float, 1: int|float}
     */
    public function getDefenderDebris(): array
    {
        $sendMetal = 0;
        $sendCrystal = 0;
        foreach ($this->getDefendersLostUnits(!REPAIRED_DO_DEBRIS) as $idPlayer => $player) {
            foreach ($player as $idFleet => $fleet) {
                foreach ($fleet as $role => $values) {
                    $metal = 0;
                    $crystal = 0;
                    foreach ($values as $idShipType => $lost) {
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
     * @return array{0: int|float, 1: int|float}
     */
    public function getDebris(): array
    {
        $aDebris = $this->getAttackerDebris();
        $dDebris = $this->getDefenderDebris();
        return [$aDebris[0] + $dDebris[0], $aDebris[1] + $dDebris[1]];
    }

    /**
     * @return array<int, array<int, int>>
     */
    public function getAttackersTech(): array
    {
        $techs = [];
        $players = $this->getRound('START')->getAfterBattleAttackers()->getIterator();
        foreach ($players as $id => $player) {
            $techs[$player->getId()] = [
                $player->getWeaponsTech(),
                $player->getShieldsTech(),
                $player->getArmourTech()];
        }
        return $techs;
    }

    /**
     * @return array<int, array<int, int>>
     */
    public function getDefendersTech(): array
    {
        $techs = [];
        $players = $this->getRound('START')->getAfterBattleDefenders()->getIterator();
        foreach ($players as $id => $player) {
            $techs[$player->getId()] = [
                $player->getWeaponsTech(),
                $player->getShieldsTech(),
                $player->getArmourTech()];
        }
        return $techs;
    }

    public function getLastRoundNumber(): int
    {
        return $this->roundsCount - 1;
    }

    public function __toString(): string
    {
        ob_start();
        $css = $this->css;
        require OPBEPATH . 'Views/report.html';
        return (string) ob_get_clean();
    }

    public function getDefendersRepaired(): PlayerGroup
    {
        $defendersBefore = $this->getRound('START')->getAfterBattleDefenders();
        $defendersAfter = $this->getRound('END')->getAfterBattleDefenders();
        return $this->getPlayerRepaired($defendersBefore, $defendersAfter);
    }

    public function getAttackersRepaired(): PlayerGroup
    {
        $attackersBefore = $this->getRound('START')->getAfterBattleAttackers();
        $attackersAfter = $this->getRound('END')->getAfterBattleAttackers();
        return $this->getPlayerRepaired($attackersBefore, $attackersAfter);
    }

    public function getAfterBattleAttackers(): PlayerGroup
    {
        $players = $this->getResultAttackersFleetOnRound('END')->cloneMe();
        $playersRepaired = $this->getAttackersRepaired();
        return $this->getAfterBattlePlayerGroup($players, $playersRepaired);
    }

    public function getAfterBattleDefenders(): PlayerGroup
    {
        $players = $this->getResultDefendersFleetOnRound('END')->cloneMe();
        $playersRepaired = $this->getDefendersRepaired();
        return $this->getAfterBattlePlayerGroup($players, $playersRepaired);
    }

    private function getAfterBattlePlayerGroup(PlayerGroup $players, PlayerGroup $playersRepaired): PlayerGroup
    {
        foreach ($playersRepaired->getIterator() as $idPlayer => $playerRepaired) {
            if (!$players->existPlayer($idPlayer)) { // player is completely destroyed
                $players->addPlayer($playerRepaired);
                continue;
            }
            $endPlayer = $players->getPlayer($idPlayer);
            if ($endPlayer === false) {
                continue;
            }
            foreach ($playerRepaired->getIterator() as $idFleet => $fleetRepaired) {
                if (!$endPlayer->existFleet($idFleet)) {
                    $endPlayer->addFleet($fleetRepaired);
                    continue;
                }
                $endFleet = $endPlayer->getFleet($idFleet);
                foreach ($fleetRepaired->getIterator() as $idShipType => $shipTypeRepaired) {
                    $endFleet->addShipType($shipTypeRepaired);
                }
            }
        }
        return $players;
    }

    private function getPlayerRepaired(PlayerGroup $playersBefore, PlayerGroup $playersAfter): PlayerGroup
    {
        $lostShips = $this->getPlayersLostShips($playersBefore, $playersAfter);
        foreach ($lostShips->getIterator() as $idPlayer => $player) {
            foreach ($player->getIterator() as $idFleet => $fleet) {
                foreach ($fleet->getIterator() as $idShipType => $shipType) {
                    $lostShips->decrement($idPlayer, $idFleet, $idShipType, (int) round($shipType->getCount() * (1 - $shipType->getRepairProb())));
                }
            }
        }
        return $lostShips;
    }

    public function getPlayersLostShips(PlayerGroup $playersBefore, PlayerGroup $playersAfter): PlayerGroup
    {
        $playersBefore_clone = $playersBefore->cloneMe();

        foreach ($playersAfter->getIterator() as $idPlayer => $playerAfter) {
            foreach ($playerAfter->getIterator() as $idFleet => $fleet) {
                foreach ($fleet->getIterator() as $idShipType => $shipType) {
                    $playersBefore_clone->decrement($idPlayer, $idFleet, $idShipType, $shipType->getCount());
                }
            }
        }
        return $playersBefore_clone;
    }

    private function getShipTypeRole(object $shipType): string
    {
        $className = get_class($shipType);
        $separatorPosition = strrpos($className, '\\');

        return $separatorPosition === false ? $className : substr($className, $separatorPosition + 1);
    }

    /**
     * @return list<int>
     */
    public function getAttackersId(): array
    {
        $array = [];

        foreach ($this->getPresentationAttackersFleetOnRound('START')->getIterator() as $id => $group) {
            $array[] = $id;
        }

        return $array;
    }

    /**
     * @return list<int>
     */
    public function getDefendersId(): array
    {
        $array = [];

        foreach ($this->getPresentationDefendersFleetOnRound('START')->getIterator() as $id => $group) {
            $array[] = $id;
        }

        return $array;
    }

    public function setSteal(mixed $array): void
    {
        $this->steal = $array;
    }

    public function getSteal(): mixed
    {
        return $this->steal;
    }
}
