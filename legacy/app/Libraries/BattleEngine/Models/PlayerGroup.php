<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\BattleEngine\Models;

use Xgp\App\Libraries\BattleEngine\CombatObject\FireManager;
use Xgp\App\Libraries\BattleEngine\Utils\IterableUtil;
use Exception;

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
 * @extends IterableUtil<Player>
 */
class PlayerGroup extends IterableUtil
{
    public ?int $battleResult = null;
    private static int $id_count = 0;
    private int $id;

    /**
     * @param Player[] $players
     */
    public function __construct(array $players = [])
    {
        $this->id = ++self::$id_count;
        foreach ($players as $player) {
            $this->addPlayer($player);
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function decrement(int $idPlayer, int $idFleet, int $idShipType, int | float $count): void
    {
        if (!$this->existPlayer($idPlayer)) {
            throw new Exception('Player with id : ' . $idPlayer . ' not exist');
        }
        $this->array[$idPlayer]->decrement($idFleet, $idShipType, $count);
        if ($this->array[$idPlayer]->isEmpty()) {
            unset($this->array[$idPlayer]);
        }
    }

    public function getPlayer(int $id): Player | false
    {
        return isset($this->array[$id]) ? $this->array[$id] : false;
    }

    public function existPlayer(int $id): bool
    {
        return isset($this->array[$id]);
    }

    public function addPlayer(Player $player): void
    {
        $this->array[$player->getId()] = $player->cloneMe(); //avoid collateral effects: when the object or array is an argument && it's saved in a structure
    }

    /**
     * @param Fleet[] $fleets
     */
    public function createPlayerIfNotExist(int $id, array $fleets, ?int $militaryTech, ?int $shieldTech, ?int $defenceTech): Player | false
    {
        if (!$this->existPlayer($id)) {
            $this->addPlayer(new Player($id, $fleets, $militaryTech, $shieldTech, $defenceTech));
        }
        return $this->getPlayer($id);
    }

    public function isEmpty(): bool
    {
        foreach ($this->array as $id => $player) {
            if (!$player->isEmpty()) {
                return false;
            }
        }
        return true;
    }

    public function __toString(): string
    {
        ob_start();
        $_playerGroup = $this;
        $_st = '';
        require OPBEPATH . 'Views/playerGroup.html';
        return (string) ob_get_clean();
    }

    /**
     * @return array<int, mixed>
     */
    public function inflictDamage(FireManager $fire): array
    {
        $physicShots = [];
        foreach ($this->array as $idPlayer => $player) {
            echo "---------** firing to player with ID = $idPlayer **---------- <br>";
            $ps = $player->inflictDamage($fire);
            $physicShots[$idPlayer] = $ps;
        }
        return $physicShots;
    }

    /**
     * @return array<int, mixed>
     */
    public function cleanShips(): array
    {
        $shipsCleaners = [];
        foreach ($this->array as $idPlayer => $player) {
            echo "---------** cleanShips to player with ID = $idPlayer **---------- <br>";
            $sc = $player->cleanShips();
            $shipsCleaners[] = $sc;
            if ($player->isEmpty()) {
                unset($this->array[$idPlayer]);
            }
        }
        return $shipsCleaners;
    }

    public function repairShields(): void
    {
        foreach ($this->array as $idPlayer => $player) {
            $player->repairShields();
        }
    }

    public function getEquivalentFleetContent(): Fleet
    {
        $merged = new Fleet(-1);
        foreach ($this->array as $idPlayer => $player) { // cloning don't have any sense because we don't touch the array,maybe php bug :(
            $merged->mergeFleet($player->getEquivalentFleetContent());
        }
        return $merged;
    }

    public function getTotalCount(): int | float
    {
        $amount = 0;
        foreach ($this->array as $idPlayer => $player) {
            $amount += $player->getTotalCount();
        }
        return $amount;
    }
    /*
      public function getFleet($idFleet)
      {
      foreach ($this->array as $idPlayer => $player)
      {
      $fleet = $player->getFleet($idFleet);
      if ($fleet !== false)
      {
      return $fleet;
      }
      }
      return false;
      }
     */

    public function cloneMe(): self
    {
        $players = array_values($this->array);
        $tmp = new PlayerGroup($players);
        $tmp->battleResult = $this->battleResult;
        $tmp->id = $this->id;
        self::$id_count--;
        return $tmp;
    }
}
