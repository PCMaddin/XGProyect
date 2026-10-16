<?php

declare(strict_types=1);

namespace App\Libraries\BattleEngine\Core;

use App\Libraries\BattleEngine\Models\PlayerGroup;

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
 * @version 21-03-2015
 *
 * @link https://github.com/jstar88/opbe
 */
class Battle
{
    private PlayerGroup $attackers;
    private PlayerGroup $defenders;
    private BattleReport $report;
    private bool $battleStarted;

    public function __construct(PlayerGroup $attackers, PlayerGroup $defenders)
    {
        $this->attackers = $attackers;
        $this->defenders = $defenders;
        $this->battleStarted = false;
        $this->report = new BattleReport();
    }

    /**
     * Battle::startBattle()
     */
    public function startBattle(): ?bool
    {
        $this->battleStarted = true;
        //only for initial fleets presentation
        $round = new Round($this->attackers, $this->defenders, 0);
        $this->report->addRound($round);
        for ($i = 1; $i <= ROUNDS; $i++) {
            $attackersLost = $this->attackers->isEmpty();
            $defendersLost = $this->defenders->isEmpty();
            //if one of they are empty then battle is ended, so update the status
            if ($attackersLost || $defendersLost) {
                $this->checkWhoWon($attackersLost, $defendersLost);
                $this->report->setBattleResult($this->attackers->battleResult, $this->defenders->battleResult);
                return null;
            }
            //initialize the round
            $round = new Round($this->attackers, $this->defenders, $i);
            $round->startRound();
            //add the round to the combatReport
            $this->report->addRound($round);
            //update the attackers and defenders after round
            $this->attackers = $round->getAfterBattleAttackers();
            $this->defenders = $round->getAfterBattleDefenders();
        }
        //check status after all rounds
        $this->checkWhoWon($this->attackers->isEmpty(), $this->defenders->isEmpty());
        return true;
    }

    /**
     * Assign to groups the status win,lose or draw
     */
    private function checkWhoWon(bool $attackersLost, bool $defendersLost): void
    {
        [$this->attackers->battleResult, $this->defenders->battleResult] = match (true) {
            $attackersLost && !$defendersLost => [BATTLE_LOSE, BATTLE_WIN],
            !$attackersLost && $defendersLost => [BATTLE_WIN, BATTLE_LOSE],
            default => [BATTLE_DRAW, BATTLE_DRAW],
        };
    }

    public function __toString(): string
    {
        return $this->report->__toString();
    }

    public function getReport(): BattleReport
    {
        if (!$this->battleStarted) {
            $this->startBattle();
        }
        return $this->report;
    }
}
