<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Records the update migrations (database/migrations/400) that the install
 * schema already contains as run.
 *
 * The installer builds the tables from database/migrations/install, which
 * hold the current state, but recorded none of the update migrations: the
 * update command (migrate --path=database/migrations/400) then ran all of
 * them again and failed on the first ("table already exists"). The installer
 * marks all of them now (--all); an installation made before marks the
 * older ones once with this command. The later ones check what they change,
 * so they run on any installation.
 */
class MarkInstalledUpdates extends Command
{
    /** Update migrations from here on check what they change. */
    public const IDEMPOTENT_FROM = '2026_10_16_000000';

    /** @var string */
    protected $signature = 'xgp:mark-installed-updates {--all : mark every update migration (fresh installation)}';

    /** @var string */
    protected $description = 'Record the update migrations an installation made with the installer already contains';

    public function handle(): int
    {
        $marked = [];
        $last = DB::table('migrations')->max('batch');
        $batch = (is_numeric($last) ? (int) $last : 0) + 1;

        foreach ($this->updateMigrations() as $name) {
            if (!$this->option('all') && strcmp($name, self::IDEMPOTENT_FROM) >= 0) {
                continue;
            }

            if (DB::table('migrations')->where('migration', $name)->exists()) {
                continue;
            }

            DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
            $marked[] = $name;
        }

        $this->info(count($marked) . ' update migration(s) recorded.');

        foreach ($marked as $name) {
            $this->line('  ' . $name);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function updateMigrations(): array
    {
        $names = array_map(
            fn (string $file): string => basename($file, '.php'),
            glob(database_path('migrations/400/*.php')) ?: []
        );
        sort($names);

        return $names;
    }
}
