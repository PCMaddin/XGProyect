<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\Versions;
use Illuminate\Console\Command;
use Throwable;

/**
 * Brings universes to the code's version: the update migrations
 * (database/migrations/400) in each of their databases. A universe pinned
 * to another version is left to that version's code: all of them are
 * skipped, a named one is migrated by its version's artisan.
 */
class MigrateInstances extends Command
{
    /** @var string */
    protected $signature = 'xgp:instance:migrate {slug? : one universe, default: all}';

    /** @var string */
    protected $description = 'Run the update migrations in the universes';

    public function handle(InstanceRegistry $registry, InstanceProvisioner $provisioner, Versions $versions): int
    {
        $provisioner->prepareRegistry();
        $slug = $this->argument('slug');
        $instances = is_string($slug) ? array_filter([$registry->find($slug)]) : $registry->all();

        if ($instances === []) {
            $this->error(is_string($slug) ? 'No universe ' . $slug . '.' : 'No universe registered.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($instances as $instance) {
            $this->info($instance->slug . ' (' . $instance->database . ')');

            if (!$instance->hasDatabase()) {
                // xgp:instance:unarchive brings it back and migrates it then
                $this->line('archived, no database');

                continue;
            }

            if (!$instance->runsOn($versions->current()) && !is_string($slug)) {
                $this->line('plays on version ' . $instance->version . ', not touched (xgp:instance:upgrade moves it)');

                continue;
            }

            try {
                $this->line(trim($versions->runFor($instance, 'xgp:instance:migrate', [$instance->slug], static fn (): string => $provisioner->migrate($instance))));
            } catch (Throwable $e) {
                $this->error($e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
