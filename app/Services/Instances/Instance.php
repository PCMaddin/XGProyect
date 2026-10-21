<?php

declare(strict_types=1);

namespace App\Services\Instances;

/**
 * A universe of its own: a database with its own players, set up by
 * `xgp:instance:create` and listed in the lobby.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final class Instance
{
    public const PAY2WIN = 'pay2win';
    public const PLAY2WIN = 'play2win';

    public const OPEN = 'open';
    public const CLOSED = 'closed';
    /** closed, its database dumped and dropped (xgp:instance:archive) */
    public const ARCHIVED = 'archived';

    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $database,
        public readonly string $mode,
        public readonly string $status,
        public readonly int $openedAt,
        public readonly ?int $endsAt = null,
        public readonly ?int $closedAt = null,
        // where an archived universe's dump lies: restic:<snapshot> or file:<path>
        public readonly string $archive = '',
        // the version of the code it plays on ('' : the one that runs)
        public readonly string $version = '',
    ) {
    }

    /**
     * Whether the code of the given version is the one this universe plays on.
     */
    public function runsOn(string $version): bool
    {
        return $this->version === '' || $this->version === $version;
    }

    /**
     * Open for play: not closed, and its season has not ended.
     */
    public function isOpen(?int $now = null): bool
    {
        return $this->status === self::OPEN && !$this->hasEnded($now ?? time());
    }

    /**
     * Its database is there (an archived universe has none until restored).
     */
    public function hasDatabase(): bool
    {
        return $this->status !== self::ARCHIVED;
    }

    /**
     * The closing time to store after a change from $before: set on
     * closing, gone on opening again, untouched otherwise.
     *
     * @return array{closed_at?: int|null}
     */
    public function closedAtSince(self $before, int $now): array
    {
        if ($this->status === $before->status) {
            return [];
        }

        return ['closed_at' => $this->status === self::CLOSED ? $now : null];
    }

    public function hasEnded(int $now): bool
    {
        return $this->endsAt !== null && $now >= $this->endsAt;
    }
}
