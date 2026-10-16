<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Core\Objects;

/**
 * The game object config (names, prices, combat specs) by object id, typed
 * for the mission handlers.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
final class MissionObjects
{
    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        $result = [];
        foreach (self::asArray(Objects::getInstance()->getObjects()) as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function prices(): array
    {
        return self::specs(Objects::getInstance()->getPrice());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function combatSpecs(): array
    {
        return self::specs(Objects::getInstance()->getCombatSpecs());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function specs(mixed $map): array
    {
        $result = [];
        foreach (self::asArray($map) as $key => $value) {
            if (!is_int($key) || !is_array($value)) {
                continue;
            }

            $result[$key] = [];
            foreach ($value as $field => $spec) {
                $result[$key][(string) $field] = $spec;
            }
        }

        return $result;
    }

    /**
     * @return array<mixed>
     */
    private static function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
