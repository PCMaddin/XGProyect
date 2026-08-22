<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\BattleEngine\Utils;

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
 * @version 21-03-2015)
 *
 * @link https://github.com/jstar88/opbe
 */
abstract class GeometricDistribution
{
    /**
     * GeometricDistribution::getProbabilityFromMean()
     *
     *
     */
    public static function getProbabilityFromMean(float $m): float
    {
        if ($m <= 1) {
            return 1;
        }
        return 1 / $m;
    }

    /**
     * GeometricDistribution::getMeanFromProbability()
     *
     *
     */
    public static function getMeanFromProbability(float $p): float
    {
        if ($p == 0) {
            return INF;
        }
        return 1 / $p;
    }

    /**
     * GeometricDistribution::getVarianceFromProbability()
     *
     *
     */
    public static function getVarianceFromProbability(float $p): float
    {
        if ($p == 0) {
            return INF;
        }
        return (1 - $p) / ($p * $p);
    }

    /**
     * GeometricDistribution::getStandardDeviationFromProbability()
     *
     *
     */
    public static function getStandardDeviationFromProbability(float $p): float
    {
        return sqrt(self::getVarianceFromProbability($p));
    }
}
