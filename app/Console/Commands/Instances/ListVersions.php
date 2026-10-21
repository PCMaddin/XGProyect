<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\Versions;
use Illuminate\Console\Command;
use Throwable;

/**
 * The versions of the code on the server and the universes playing on
 * each; --prune deletes the directories below the oldest version in use.
 */
class ListVersions extends Command
{
    /** @var string */
    protected $signature = 'xgp:versions {--prune : delete the versions below the oldest one a universe plays on}';

    /** @var string */
    protected $description = 'List the versions of the code on the server, and prune the unused ones';

    public function handle(Versions $versions): int
    {
        if ($this->option('prune')) {
            try {
                $deleted = $versions->prune();
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info($deleted === [] ? 'Nothing to prune.' : 'Deleted: ' . implode(', ', $deleted) . '.');
        }

        $use = $versions->inUse();
        $rows = [];

        foreach ($versions->available() as $version) {
            $rows[] = [
                $version,
                $version === $versions->current() ? 'running' : '',
                (string) $versions->directory($version),
                implode(', ', $use[$version] ?? []),
            ];
        }

        foreach ($use as $version => $slugs) {
            if ($versions->directory($version) === null) {
                $rows[] = [$version, 'MISSING', '', implode(', ', $slugs)];
            }
        }

        $this->table(['Version', '', 'Directory', 'Universes'], $rows);
        $this->line('Versions path: ' . $versions->root());

        return self::SUCCESS;
    }
}
