<?php

declare(strict_types=1);

namespace App\Libraries\BattleEngine\CombatObject;

use App\Libraries\BattleEngine\Models\Fleet;
use App\Libraries\BattleEngine\Models\ShipType;
use App\Libraries\BattleEngine\Utils\Gauss;
use App\Libraries\BattleEngine\Utils\GeometricDistribution;
use App\Libraries\BattleEngine\Utils\Math;
use App\Libraries\BattleEngine\Utils\Number;

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
 * @version 21-03-2015
 *
 * @link https://github.com/jstar88/opbe
 *
 *
 * Fire
 *
 * This class rappresent the fire shotted by attackers to defenders or viceversa.
 * Using probabilistic theory, this class will help you in RF(Rapid Fire) calculation with O(1) time and memory functions.
 */
/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Fire
{
    private ShipType $attackerShipType;
    private Fleet $defenderFleet;
    private int | float $shots = 0;
    private int | float $power = 0;

    public function __construct(ShipType $attackerShipType, Fleet $defenderFleet)
    {
        $this->attackerShipType = $attackerShipType;
        $this->defenderFleet = $defenderFleet;
        $this->calculateTotal();
    }

    public function getPower(): int | float
    {
        return $this->attackerShipType->getPower();
    }

    public function getId(): int
    {
        return $this->attackerShipType->getId();
    }
    //----------- SENDED FIRE -------------

    /**
     * The total fire power.
     */
    public function getAttackerTotalFire(): int | float
    {
        return $this->power;
    }

    /**
     * The total amount of shots.
     */
    public function getAttackerTotalShots(): int | float
    {
        return $this->shots;
    }

    /**
     * The total power and shots of the attacker: one shot per ship plus the
     * rapid fire shots.
     */
    private function calculateTotal(): void
    {
        $this->shots += $this->attackerShipType->getCount();
        $this->power += $this->getNormalPower();

        $rapidFireShots = (int) round($this->getShotsFromOneAttackerShipOfType($this->attackerShipType) * $this->attackerShipType->getCount());
        $this->power += $rapidFireShots * $this->attackerShipType->getPower();
        $this->shots += $rapidFireShots;
    }

    /**
     * The rapid fire shots of one ship of the type at the whole defending
     * fleet: the mean of the geometric distribution, randomly varied by up to
     * MAX_RF_BUFF / MAX_RF_NERF.
     */
    private function getShotsFromOneAttackerShipOfType(ShipType $shipType): int
    {
        $probability = $this->getProbabilityToShotAgainForAttackerShipOfType($shipType);
        $meanShots = GeometricDistribution::getMeanFromProbability(1 - $probability) - 1;
        $max = $meanShots * (1 + MAX_RF_BUFF);
        $min = $meanShots * (1 - MAX_RF_NERF);

        return (int) Gauss::getNextMsBetween($meanShots, GeometricDistribution::getStandardDeviationFromProbability(1 - $probability), $min, $max);
    }

    /**
     * The probability of a ship of the type to shoot again thanks to rapid
     * fire, weighted by the share of each defending ship type.
     */
    private function getProbabilityToShotAgainForAttackerShipOfType(ShipType $shipType): float
    {
        $probability = 0.0;
        foreach ($this->defenderFleet->getIterator() as $defender) {
            $rapidFire = $shipType->getRfTo($defender);
            $probabilityToShotAgain = 1 - GeometricDistribution::getProbabilityFromMean($rapidFire);
            $probabilityToHitThisType = $defender->getCount() / $this->defenderFleet->getTotalCount();
            $probability += $probabilityToShotAgain * $probabilityToHitThisType;
        }
        return $probability;
    }

    /**
     * The total fire of the attacker ship type without rapid fire.
     */
    private function getNormalPower(): int | float
    {
        return $this->attackerShipType->getCount() * $this->attackerShipType->getPower();
    }

    //------- INCOMING FIRE------------

    /**
     * The shots this attacker ship type fires at the given defender ship type
     * (its share of the defending fleet): whole shots plus the fractional rest.
     */
    public function getShotsFiredByAllToDefenderType(ShipType $defender): Number
    {
        return Math::multiple(
            new Number($this->getAttackerTotalShots() / $this->defenderFleet->getTotalCount()),
            new Number($defender->getCount())
        );
    }

    /**
     * Rappresentation of this object
     */
    public function __toString(): string
    {
        return $this->getAttackerTotalFire() . '';
    }
}
