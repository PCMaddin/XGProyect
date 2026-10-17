<?php

declare(strict_types=1);

namespace App\Services\Instances;

/**
 * A universe of its own: a database with its own players, set up by
 * `xgp:instance:create` and listed in the lobby.
 */
final class Instance
{
    public const PAY2WIN = 'pay2win';
    public const PLAY2WIN = 'play2win';

    public const OPEN = 'open';
    public const CLOSED = 'closed';

    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $database,
        public readonly string $mode,
        public readonly string $status,
        public readonly int $openedAt,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
