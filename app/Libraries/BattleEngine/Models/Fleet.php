<?php

declare(strict_types=1);

namespace App\Libraries\BattleEngine\Models;

use App\Libraries\BattleEngine\CombatObject\PhysicShot;
use App\Libraries\BattleEngine\Utils\RendersView;
use App\Libraries\BattleEngine\CombatObject\FireManager;
use App\Libraries\BattleEngine\CombatObject\ShipsCleaner;
use App\Libraries\BattleEngine\Utils\IterableUtil;

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
 * @copyright 2015 Jstar <frascafresca@gmail.com>
 * @license http://www.gnu.org/licenses/ GNU AGPLv3 License
 *
 * @version 23-3-2015)
 *
 * @link https://github.com/jstar88/opbe
 */
/**
 * @extends IterableUtil<ShipType>
 */
class Fleet extends IterableUtil
{
    use RendersView;

    private int | float $count = 0;
    private int $id;
    // added but only used in report templates
    private int $weaponsTech = 0;
    private int $shieldsTech = 0;
    private int $armourTech = 0;
    private string $name;
    private ?int $galaxy = null;
    private ?int $system = null;
    private ?int $planet = null;

    /**
     * @param ShipType[] $shipTypes
     */
    public function __construct(int $id, array $shipTypes = [], ?int $weaponsTech = null, ?int $shieldsTech = null, ?int $armourTech = null, string $name = '', ?int $galaxy = null, ?int $system = null, ?int $planet = null)
    {
        $this->id = $id;
        $this->count = 0;
        $this->name = $name;
        if ($this->id != -1) {
            $this->setTech($weaponsTech, $shieldsTech, $armourTech);
            $this->setCoords($galaxy, $system, $planet);
        }
        foreach ($shipTypes as $shipType) {
            $this->addShipType($shipType);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setTech(?int $weapons = null, ?int $shields = null, ?int $armour = null): void
    {
        foreach ($this->array as $shipType) {
            $shipType->setWeaponsTech($weapons);
            $shipType->setShieldsTech($shields);
            $shipType->setArmourTech($armour);
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
        $this->galaxy = $galaxy;
        $this->system = $system;
        $this->planet = $planet;
    }

    public function addShipType(ShipType $shipType): void
    {
        if (isset($this->array[$shipType->getId()])) {
            $this->array[$shipType->getId()]->increment($shipType->getCount());
            $this->count += $shipType->getCount();

            return;
        }

        $shipType = $shipType->cloneMe(); //avoid collateral effects
        if ($this->id != -1) {
            $shipType->setWeaponsTech($this->weaponsTech);
            $shipType->setShieldsTech($this->shieldsTech);
            $shipType->setArmourTech($this->armourTech);
        }
        $this->array[$shipType->getId()] = $shipType;
        $this->count += $shipType->getCount();
    }

    public function decrement(int $id, int | float $count): void
    {
        $this->array[$id]->decrement($count);
        $this->count -= $count;
        if ($this->array[$id]->getCount() <= 0) {
            unset($this->array[$id]);
        }
    }

    public function mergeFleet(Fleet $other): void
    {
        foreach ($other->getIterator() as $shipType) {
            $this->addShipType($shipType);
        }
    }

    public function getShipType(int $id): ShipType
    {
        return $this->array[$id];
    }

    public function existShipType(int $id): bool
    {
        return isset($this->array[$id]);
    }

    public function getTotalCount(): int | float
    {
        return $this->count;
    }

    public function __toString(): string
    {
        return $this->renderView('fleet.html', ['_fleet' => $this, '_st' => '']);
    }

    /**
     * @return array<int, list<PhysicShot>> the physic shots by ship type
     */
    public function inflictDamage(FireManager $fires): array
    {
        $physicShots = [];
        //doesn't matter who shot first, but who receive first the damage
        foreach ($fires->getIterator() as $fire) {
            $tmp = [];
            foreach ($this->getOrderedIterator() as $idShipTypeDefender => $shipTypeDefender) {
                $xs = $fire->getShotsFiredByAllToDefenderType($shipTypeDefender);
                $ps = $shipTypeDefender->inflictDamage($fire->getPower(), $xs->result);
                $tmp[$idShipTypeDefender] = $xs->rest;
                if ($ps !== null) {
                    $physicShots[$idShipTypeDefender][] = $ps;
                }
            }
            // assign the last shot to the more likely shitType
            $maxRest = 0;
            $lastShotTarget = 0;
            foreach ($tmp as $shipTypeId => $rest) {
                if ($rest > $maxRest) {
                    $maxRest = $rest;
                    $lastShotTarget = $shipTypeId;
                }
            }
            if ($lastShotTarget != 0) {
                $ps = $this->getShipType($lastShotTarget)->inflictDamage($fire->getPower(), 1);
                if ($ps !== null) {
                    $physicShots[$lastShotTarget][] = $ps;
                }
            }
        }
        return $physicShots;
    }

    /**
     * @return array<int, ShipType>
     */
    public function getOrderedIterator(): array
    {
        ksort($this->array);

        return $this->array;
    }

    /**
     * @return array<int, ShipsCleaner>
     */
    public function cleanShips(): array
    {
        $shipsCleaners = [];
        foreach ($this->array as $id => $shipType) {
            $sc = $shipType->cleanShips();
            $this->count -= $sc->getExplodedShips();
            if ($shipType->isEmpty()) {
                unset($this->array[$id]);
            }
            $shipsCleaners[$shipType->getId()] = $sc;
        }
        return $shipsCleaners;
    }

    public function repairShields(): void
    {
        foreach ($this->array as $shipTypeDefender) {
            $shipTypeDefender->repairShields();
        }
    }

    public function isEmpty(): bool
    {
        foreach ($this->array as $shipType) {
            if (!$shipType->isEmpty()) {
                return false;
            }
        }
        return true;
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

    public function cloneMe(): static
    {
        $types = array_values($this->array);
        $class = get_class($this);
        return new $class($this->id, $types, $this->weaponsTech, $this->shieldsTech, $this->armourTech, $this->name, $this->galaxy, $this->system, $this->planet);
    }
}
