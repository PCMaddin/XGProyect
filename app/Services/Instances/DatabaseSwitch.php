<?php

declare(strict_types=1);

namespace App\Services\Instances;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Points a universe at another database, e.g. one restored from a backup
 * (xgp:backup:restore): only a database that exists, holds a game and is no
 * other universe's. The old database stays until it is dropped by hand.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class DatabaseSwitch
{
    public function __construct(private InstanceRegistry $registry)
    {
    }

    /**
     * @throws RuntimeException
     */
    public function switch(Instance $instance, string $database): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new RuntimeException('Not a database name: ' . $database);
        }

        foreach ($this->registry->all() as $other) {
            if ($other->slug !== $instance->slug && $other->database === $database) {
                throw new RuntimeException('The database ' . $database . ' belongs to the universe ' . $other->slug . '.');
            }
        }

        // raw: the table prefix must not reach information_schema
        $holdsGame = DB::connection(InstanceRegistry::CONNECTION)
            ->select("SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE '%migrations'", [$database]);

        if ($holdsGame === []) {
            throw new RuntimeException('The database ' . $database . ' does not exist or holds no game.');
        }

        $this->registry->update($instance->slug, ['database' => $database]);
    }
}
