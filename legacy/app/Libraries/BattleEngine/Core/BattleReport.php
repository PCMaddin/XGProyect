<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\BattleEngine\Core;

use Exception;
use Xgp\App\Libraries\BattleEngine\Models\PlayerGroup;
use Xgp\App\Libraries\BattleEngine\Utils\Events;
use Xgp\App\Libraries\BattleEngine\Utils\RendersView;

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
/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class BattleReport
{
    use RendersView;

    /** @var array<int, Round> */
    private array $rounds = [];
    private int $roundsCount = 0;
    /** @var array<string, int|float> */
    private array $steal = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];
    /** @var array{size: int, fields: float}|false|null */
    private array | false | null $moonEvent = null;
    private BattleLosses $losses;

    public function __construct()
    {
        $this->losses = new BattleLosses();
    }

    /**
     * Store a round
     */
    public function addRound(Round $round): void
    {
        $this->rounds[$this->roundsCount++] = $round;
    }

    /**
     * Retrive a round.
     *
     * @param string|int $number "START" to get the first round, "END" to get the last one, an integer(from zero) to get the corrispective round
     */
    public function getRound(string | int $number): Round
    {
        if ($number === 'END') {
            return $this->rounds[$this->roundsCount - 1];
        }

        if ($number === 'START') {
            return $this->rounds[0];
        }

        if (intval($number) < 0 || intval($number) > $this->getLastRoundNumber()) {
            throw new Exception('Invalid round number');
        }

        return $this->rounds[intval($number)];
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
     * Check if attackers won the battle
     */
    public function attackerHasWin(): bool
    {
        return $this->getRound('END')->getAfterBattleAttackers()->battleResult === BATTLE_WIN;
    }

    /**
     * Check if defenders won the battle
     */
    public function defenderHasWin(): bool
    {
        return $this->getRound('END')->getAfterBattleDefenders()->battleResult === BATTLE_WIN;
    }

    /**
     * Check if the battle ended with a draw
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
        return $this->getRound($number)->getAfterBattleAttackers();
    }

    public function getResultDefendersFleetOnRound(string | int $number): PlayerGroup
    {
        return $this->getRound($number)->getAfterBattleDefenders();
    }

    //-------------------  Lost units functions -------------------
    public function getTotalAttackersLostUnits(): int | float
    {
        return BattleLosses::total($this->getAttackersLostUnits());
    }

    public function getTotalDefendersLostUnits(): int | float
    {
        return BattleLosses::total($this->getDefendersLostUnits());
    }

    /**
     * The value of the attackers' lost units, without the repaired ones.
     *
     * @return array<int, array<int, array<string, array<int, array{0: int|float, 1: int|float}>>>>
     */
    public function getAttackersLostUnits(): array
    {
        return $this->losses->lostUnits($this->getRound('START')->getAfterBattleAttackers(), $this->getRound('END')->getAfterBattleAttackers(), true);
    }

    /**
     * The value of the defenders' lost units, without the repaired ones.
     *
     * @return array<int, array<int, array<string, array<int, array{0: int|float, 1: int|float}>>>>
     */
    public function getDefendersLostUnits(): array
    {
        return $this->losses->lostUnits($this->getRound('START')->getAfterBattleDefenders(), $this->getRound('END')->getAfterBattleDefenders(), true);
    }

    /**
     * The value of all destroyed units of the defenders, also the repaired
     * ones (they count for the debris).
     *
     * @return array<int, array<int, array<string, array<int, array{0: int|float, 1: int|float}>>>>
     */
    public function getDefendersDestroyedUnits(): array
    {
        return $this->losses->lostUnits($this->getRound('START')->getAfterBattleDefenders(), $this->getRound('END')->getAfterBattleDefenders(), false);
    }

    //--------------------------------------------------------------
    /**
     * Roll the moon creation (the chance grows with the debris).
     *
     * @return array{size: int, fields: float}|false
     */
    public function tryMoon(): array | false
    {
        $prob = $this->getMoonProb();

        $this->moonEvent = mt_rand(0, 99) < $prob ? Events::eventMoon($prob) : false;

        return $this->moonEvent;
    }

    /**
     * @return array{size: int, fields: float}|false|null
     */
    public function getMoonEvent(): array | false | null
    {
        return $this->moonEvent;
    }

    public function getMoonProb(): int
    {
        return (int) min(floor(array_sum($this->getDebris()) / MOON_UNIT_PROB), MAX_MOON_PROB);
    }

    /**
     * The debris of the attackers' destroyed units (repaired ones included).
     *
     * @return array{0: int|float, 1: int|float}
     */
    public function getAttackerDebris(): array
    {
        return $this->losses->debris($this->losses->lostUnits($this->getRound('START')->getAfterBattleAttackers(), $this->getRound('END')->getAfterBattleAttackers(), false));
    }

    /**
     * The debris of the defenders' destroyed units (repaired ones included).
     *
     * @return array{0: int|float, 1: int|float}
     */
    public function getDefenderDebris(): array
    {
        return $this->losses->debris($this->getDefendersDestroyedUnits());
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
        return self::techs($this->getRound('START')->getAfterBattleAttackers());
    }

    /**
     * @return array<int, array<int, int>>
     */
    public function getDefendersTech(): array
    {
        return self::techs($this->getRound('START')->getAfterBattleDefenders());
    }

    /**
     * @return array<int, array<int, int>>
     */
    private static function techs(PlayerGroup $players): array
    {
        $techs = [];
        foreach ($players->getIterator() as $player) {
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
        return $this->renderView('report.html');
    }

    public function getDefendersRepaired(): PlayerGroup
    {
        return $this->losses->repaired($this->getRound('START')->getAfterBattleDefenders(), $this->getRound('END')->getAfterBattleDefenders());
    }

    public function getAttackersRepaired(): PlayerGroup
    {
        return $this->losses->repaired($this->getRound('START')->getAfterBattleAttackers(), $this->getRound('END')->getAfterBattleAttackers());
    }

    public function getAfterBattleAttackers(): PlayerGroup
    {
        return $this->losses->afterBattle($this->getResultAttackersFleetOnRound('END')->cloneMe(), $this->getAttackersRepaired());
    }

    public function getAfterBattleDefenders(): PlayerGroup
    {
        return $this->losses->afterBattle($this->getResultDefendersFleetOnRound('END')->cloneMe(), $this->getDefendersRepaired());
    }

    public function getPlayersLostShips(PlayerGroup $playersBefore, PlayerGroup $playersAfter): PlayerGroup
    {
        return $this->losses->lostShips($playersBefore, $playersAfter);
    }

    /**
     * @return list<int>
     */
    public function getAttackersId(): array
    {
        return array_keys($this->getPresentationAttackersFleetOnRound('START')->getIterator());
    }

    /**
     * @return list<int>
     */
    public function getDefendersId(): array
    {
        return array_keys($this->getPresentationDefendersFleetOnRound('START')->getIterator());
    }

    /**
     * @param array<string, int|float> $steal the loot by resource
     */
    public function setSteal(array $steal): void
    {
        $this->steal = $steal;
    }

    /**
     * @return array<string, int|float>
     */
    public function getSteal(): array
    {
        return $this->steal;
    }
}
