<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\Versions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * The update migrations of the base database, as deploy.sh runs them before
 * the universes'. When the base database is a universe itself (adopted with
 * xgp:instance:adopt) that plays on another version of the code, it is left
 * alone: that version's code migrates it (xgp:instance:upgrade).
 */
class MigrateBase extends Command
{
    /** @var string */
    protected $signature = 'xgp:versions:migrate-base';

    /** @var string */
    protected $description = 'Run the update migrations in the base database, unless a universe on another version plays in it';

    public function handle(InstanceRegistry $registry, InstanceProvisioner $provisioner, Versions $versions): int
    {
        try {
            $provisioner->prepareRegistry();
            $default = config('database.default');
            $base = config('database.connections.' . (is_string($default) ? $default : '') . '.database');

            foreach ($registry->all() as $instance) {
                if ($instance->database === $base && !$instance->runsOn($versions->current())) {
                    $this->info('The base database is the universe ' . $instance->slug . ', which plays on version ' . $instance->version . ': not touched.');

                    return self::SUCCESS;
                }
            }

            $exit = Artisan::call('migrate', ['--path' => 'database/migrations/400', '--force' => true]);
            $this->line(trim(Artisan::output()));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }
}
