<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Brings universes to the code's version: the update migrations
 * (database/migrations/400) in each of their databases.
 */
class MigrateInstances extends Command
{
    /** @var string */
    protected $signature = 'xgp:instance:migrate {slug? : one universe, default: all}';

    /** @var string */
    protected $description = 'Run the update migrations in the universes';

    public function handle(InstanceRegistry $registry, InstanceProvisioner $provisioner): int
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

            try {
                $this->line(trim($provisioner->migrate($instance)));
            } catch (Throwable $e) {
                $this->error($e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
