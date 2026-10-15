<?php

declare(strict_types=1);

namespace Xgp\App\Core\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Run legacy raw SQL with bound parameters instead of string interpolation.
 *
 * Values from legacy database rows are untyped (mixed). Every binding is
 * rendered to exactly the string the former interpolation produced, so the
 * SQL semantics stay the same while the driver escapes the values.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
trait BindsLegacyQueries
{
    use PreparesLegacySql;

    /**
     * @param list<mixed> $bindings
     */
    private function statement(string $sql, array $bindings = []): void
    {
        DB::statement($this->prepareSql($sql), self::bindings($bindings));
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return array<string, mixed>
     */
    private function selectRow(string $sql, array $bindings): array
    {
        return self::toRow(DB::selectOne($this->prepareSql($sql), self::bindings($bindings)));
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    private function selectRows(string $sql, array $bindings): array
    {
        $rows = [];
        foreach (DB::select($this->prepareSql($sql), self::bindings($bindings)) as $row) {
            $rows[] = self::toRow($row);
        }

        return $rows;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<string>
     */
    private static function bindings(array $bindings): array
    {
        return array_map(self::str(...), $bindings);
    }

    /**
     * The string PHP's interpolation produces for a scalar/null value
     * (used for SQL bindings as well as message text).
     */
    protected static function str(mixed $value): string
    {
        return is_scalar($value) || $value === null ? (string) $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    protected static function toRow(mixed $row): array
    {
        if (!is_object($row) && !is_array($row)) {
            return [];
        }

        $result = [];
        foreach ((array) $row as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    protected static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    protected static function num(mixed $value): int | float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_numeric($value) ? $value + 0 : 0;
    }
}
