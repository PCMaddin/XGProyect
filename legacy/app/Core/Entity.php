<?php

declare(strict_types=1);

namespace Xgp\App\Core;

/**
 * A database row as read-only entity: the column values (scalars or null).
 *
 * @phpstan-type Column int|float|string|bool|null
 *
 * @deprecated v4.0.0 use laravel instead
 */
class Entity
{
    /** @var array<string, Column> */
    protected array $data = [];

    /**
     * @param array<mixed> $data a database row (non-scalar values are dropped)
     */
    public function __construct(array $data)
    {
        foreach ($data as $column => $value) {
            $this->data[(string) $column] = is_scalar($value) ? $value : null;
        }
    }
}
