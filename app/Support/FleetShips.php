<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The ships of a fleet as stored in fleet_array: a serialized map of ship id
 * to count.
 */
final class FleetShips
{
    /**
     * @param array<array-key, mixed> $ships
     */
    public static function encode(array $ships): string
    {
        return serialize($ships);
    }

    /**
     * Ship id => count, both as int so callers can count with them. An empty
     * value is a fleet without ships.
     *
     * @return array<int, int>
     */
    public static function decode(string $fleetArray): array
    {
        if ($fleetArray === '') {
            return [];
        }

        // no objects: a tampered row cannot instantiate classes
        $ships = unserialize($fleetArray, ['allowed_classes' => false]);

        if (!is_array($ships)) {
            return [];
        }

        $decoded = [];

        foreach ($ships as $shipId => $count) {
            $decoded[is_numeric($shipId) ? (int) $shipId : 0] = is_numeric($count) ? (int) $count : 0;
        }

        return $decoded;
    }
}
