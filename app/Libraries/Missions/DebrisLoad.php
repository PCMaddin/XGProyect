<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

/**
 * How much metal and crystal a fleet loads from a debris field.
 */
final class DebrisLoad
{
    /**
     * @return array{metal: int|float, crystal: int|float}
     */
    public static function split(int | float $metal, int | float $crystal, int | float $capacity): array
    {
        $capacity = max(0, $capacity);

        // everything fits into the holds
        if ($metal + $crystal <= $capacity) {
            return ['metal' => $metal, 'crystal' => $crystal];
        }

        // both resources exceed half of the capacity: split it evenly
        if ($metal > $capacity / 2 && $crystal > $capacity / 2) {
            return ['metal' => $capacity / 2, 'crystal' => $capacity / 2];
        }

        // take all of the smaller resource and fill the rest with the bigger one
        if ($metal > $crystal) {
            return ['metal' => min($metal, $capacity - $crystal), 'crystal' => $crystal];
        }

        return ['metal' => $metal, 'crystal' => min($crystal, $capacity - $metal)];
    }
}
