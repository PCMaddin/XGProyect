<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\BattleEngine\Utils;

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
class Gauss
{
    /**
     * Random::getNext()
     * Return an random normal number
     *
     */
    public static function getNext(): float
    {
        $first = (float) mt_rand() / (float) mt_getrandmax();
        $second = (float) mt_rand() / (float) mt_getrandmax();
        return sqrt(-2 * log($first)) * cos(2 * pi() * $second);
    }

    /**
     * Random::getNextMs()
     * Generates a random number from the normal distribution with specific mean and standard deviation
     *
     *
     */
    public static function getNextMs(float $mean, float $deviation): float
    {
        return self::getNext() * $deviation + $mean;
    }

    /**
     * Random::getNextMsBetween()
     * Generates a random number from the normal distribution with specific mean and standard deviation.
     * The number must be between min and max.
     *
     *
     */
    public static function getNextMsBetween(float $mean, float $deviation, float $min, float $max): float | int
    {
        if ($min > $mean || $max < $mean) {
            throw new Exception('Mean is not bounded by min and max');
        }

        // up to 11 tries, then a uniform pick
        for ($try = 0; $try <= 10; $try++) {
            $next = self::getNextMs($mean, $deviation);
            if ($next >= $min && $next <= $max) {
                return $next;
            }
        }

        return mt_rand(intval($min), intval($max));
    }
}
