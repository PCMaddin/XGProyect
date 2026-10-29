<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Builds read-only Eloquent models from rows fetched with raw SQL.
 */
trait HydratesModels
{
    /**
     * A model for a database row. Every cast column the row lacks or holds
     * as NULL gets the empty value of its type (0, 0.0, '', false), as the
     * entity getters the models replace returned; so the typed properties
     * hold what they declare.
     *
     * @template TModel of Model
     *
     * @param class-string<TModel> $class
     * @param array<mixed>         $row
     *
     * @return TModel
     */
    private static function hydrateRow(string $class, array $row): Model
    {
        $model = new $class();
        $attributes = [];

        foreach ($row as $column => $value) {
            $attributes[(string) $column] = is_scalar($value) ? $value : null;
        }

        foreach ($model->getCasts() as $column => $cast) {
            if (($attributes[$column] ?? null) === null) {
                $attributes[$column] = match ($cast) {
                    'int', 'integer' => 0,
                    'float', 'double', 'real' => 0.0,
                    'bool', 'boolean' => false,
                    'string' => '',
                    default => null,
                };
            }
        }

        return $model->newFromBuilder($attributes);
    }
}
