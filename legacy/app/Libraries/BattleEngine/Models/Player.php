<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\BattleEngine\Models;

use Xgp\App\Libraries\BattleEngine\CombatObject\PhysicShot;
use Xgp\App\Libraries\BattleEngine\CombatObject\ShipsCleaner;
use Xgp\App\Libraries\BattleEngine\Utils\RendersView;
use Xgp\App\Libraries\BattleEngine\CombatObject\FireManager;
use Xgp\App\Libraries\BattleEngine\Utils\IterableUtil;

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
 * @extends IterableUtil<Fleet>
 */
class Player extends IterableUtil
{
    use RendersView;

    private int $id;
    private int $weaponsTech = 0;
    private int $shieldsTech = 0;
    private int $armourTech = 0;
    private string $name;
    private ?int $galaxy = null;
    private ?int $system = null;
    private ?int $planet = null;

    /**
     * @param Fleet[] $fleets
     */
    public function __construct(int $id, array $fleets = [], ?int $weaponsTech = null, ?int $shieldsTech = null, ?int $armourTech = null, string $name = '', ?int $galaxy = null, ?int $system = null, ?int $planet = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->setTech($weaponsTech, $shieldsTech, $armourTech);
        $this->setCoords($galaxy, $system, $planet);
        foreach ($fleets as $fleet) {
            $this->addFleet($fleet);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        foreach ($this->array as $fleet) {
            $fleet->setName($name);
        }
    }

    public function addFleet(Fleet $fleet): void
    {
        $fleet = $fleet->cloneMe();
        $fleet->setTech($this->weaponsTech, $this->shieldsTech, $this->armourTech);
        $fleet->setName($this->name);
        $fleet->setCoords($this->galaxy, $this->system, $this->planet);
        $this->array[$fleet->getId()] = $fleet; //avoid collateral effects: when the object or array is an argument && it's saved in a structure
    }

    public function setTech(?int $weapons = null, ?int $shields = null, ?int $armour = null): void
    {
        foreach ($this->array as $fleet) {
            $fleet->setTech($weapons, $shields, $armour);
        }
        if (is_numeric($weapons)) {
            $this->weaponsTech = intval($weapons);
        }
        if (is_numeric($shields)) {
            $this->shieldsTech = intval($shields);
        }
        if (is_numeric($armour)) {
            $this->armourTech = intval($armour);
        }
    }

    public function setCoords(?int $galaxy = null, ?int $system = null, ?int $planet = null): void
    {
        foreach ($this->array as $fleet) {
            $fleet->setCoords($galaxy, $system, $planet);
        }
        if (is_numeric($galaxy)) {
            $this->galaxy = intval($galaxy);
        }
        if (is_numeric($system)) {
            $this->system = intval($system);
        }
        if (is_numeric($planet)) {
            $this->planet = intval($planet);
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function decrement(int $idFleet, int $idShipType, int | float $count): void
    {
        $this->array[$idFleet]->decrement($idShipType, $count);
        if ($this->array[$idFleet]->isEmpty()) {
            unset($this->array[$idFleet]);
        }
    }

    public function getWeaponsTech(): int
    {
        return $this->weaponsTech;
    }

    public function getShieldsTech(): int
    {
        return $this->shieldsTech;
    }

    public function getArmourTech(): int
    {
        return $this->armourTech;
    }

    public function getGalaxy(): ?int
    {
        return $this->galaxy;
    }

    public function getSystem(): ?int
    {
        return $this->system;
    }

    public function getPlanet(): ?int
    {
        return $this->planet;
    }

    public function getFleet(int $id): Fleet
    {
        return $this->array[$id];
    }

    public function existFleet(int $idFleet): bool
    {
        return isset($this->array[$idFleet]);
    }

    public function isEmpty(): bool
    {
        foreach ($this->array as $fleet) {
            if (!$fleet->isEmpty()) {
                return false;
            }
        }
        return true;
    }

    public function __toString(): string
    {
        return $this->renderView('player2.html', ['_player' => $this, '_st' => '']);
    }

    /**
     * @return array<int, array<int, list<PhysicShot>>> the physic shots by fleet and ship type
     */
    public function inflictDamage(FireManager $fire): array
    {
        $physicShots = [];
        foreach ($this->array as $idFleet => $fleet) {
            $ps = $fleet->inflictDamage($fire);
            $physicShots[$idFleet] = $ps;
        }
        return $physicShots;
    }

    /**
     * @return array<int, array<int, ShipsCleaner>>
     */
    public function cleanShips(): array
    {
        $shipsCleaners = [];
        foreach ($this->array as $idFleet => $fleet) {
            $sc = $fleet->cleanShips();
            $shipsCleaners[$this->getId()] = $sc;
            if ($fleet->isEmpty()) {
                unset($this->array[$idFleet]);
            }
        }
        return $shipsCleaners;
    }

    public function repairShields(): void
    {
        foreach ($this->array as $fleet) {
            $fleet->repairShields();
        }
    }

    public function getEquivalentFleetContent(): Fleet
    {
        $merged = new Fleet(-1);
        foreach ($this->array as $fleet) {
            $merged->mergeFleet($fleet);
        }
        return $merged;
    }

    public function getTotalCount(): int | float
    {
        $amount = 0;
        foreach ($this->array as $fleet) {
            $amount += $fleet->getTotalCount();
        }
        return $amount;
    }

    public function cloneMe(): self
    {
        $fleets = array_values($this->array);
        return new Player(
            $this->id,
            $fleets,
            $this->weaponsTech,
            $this->shieldsTech,
            $this->armourTech,
            $this->name,
            $this->galaxy,
            $this->system,
            $this->planet
        );
    }
}
