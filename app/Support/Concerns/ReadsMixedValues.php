<?php

declare(strict_types=1);

namespace App\Support\Concerns;

/**
 * Typed reads of untyped values (database rows, session data, config): a
 * value that does not fit counts as 0 / 0.0 / ''.
 */
trait ReadsMixedValues
{
    private static function asInt(mixed $value): int
    {
        return is_numeric($value) || is_bool($value) ? (int) $value : 0;
    }

    private static function asFloat(mixed $value): float
    {
        return is_numeric($value) || is_bool($value) ? (float) $value : 0.0;
    }

    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
