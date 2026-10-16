<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\BattleEngine\CombatObject;

use Exception;
use Xgp\App\Libraries\BattleEngine\Models\ShipType;

/**
 *  OPBE
 *  Copyright (C) 2015  Jstar
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
 * @version 6-3-2015
 *
 * @link https://github.com/jstar88/opbe
 */
class ShipsCleaner
{
    private ShipType $fighters;
    private int $lastShipHit;
    private int $lastShots;
    private int $exploded = 0;
    private float $remainLife = 0;

    public function __construct(ShipType $shipType, int $lastShipHit, int $lastShots)
    {
        if ($lastShipHit < 0) {
            throw new Exception('Negative $lastShipHit');
        }
        if ($lastShots < 0) {
            throw new Exception('Negative $lastShots');
        }
        $this->fighters = $shipType->cloneMe();
        $this->lastShipHit = $lastShipHit;
        $this->lastShots = $lastShots;
    }

    /**
     * Start the system
     */
    public function start(): void
    {
        /*         * * calculating probability to explode ** */

        //the mean probably to explode based on damage
        $prob = 1 - $this->fighters->getCurrentLife() / ($this->fighters->getHull() * $this->fighters->getCount());
        if ($prob < 0 && $prob > -EPSILON) {
            $prob = 0;
        }
        if ($prob < 0) {
            throw new Exception('Negative prob');
        }
        // statistically, unless most of the ships are hit: then the more realistic way applies
        $probToExplode = $prob * (1 - MIN_PROB_TO_EXPLODE);
        if ($this->lastShipHit >= $this->fighters->getCount() / PROB_TO_REAL_MAGIC) {
            $probToExplode = $prob < MIN_PROB_TO_EXPLODE ? 0 : $prob;
        }

        /*         * * calculating the amount of exploded ships ** */

        // bounded by the total shots fired to simulate a real combat
        $this->exploded = min((int) round($this->fighters->getCount() * $probToExplode), $this->lastShots);

        /*         * * calculating the life of destroyed ships ** */

        $this->remainLife = $this->fighters->getCurrentLife() / $this->fighters->getCount();
    }

    public function getExplodedShips(): int
    {
        return $this->exploded;
    }

    /**
     * Return the life of exploded ships
     */
    public function getRemainLife(): float
    {
        return $this->remainLife;
    }
}
