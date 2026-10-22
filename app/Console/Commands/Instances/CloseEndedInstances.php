<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\Instance;
use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\Versions;
use Illuminate\Console\Command;
use Throwable;

/**
 * Closes the universes whose season has ended; the scheduler runs it every
 * few minutes. Players cannot enter an ended universe even before.
 */
class CloseEndedInstances extends Command
{
    /** @var string */
    protected $signature = 'xgp:instance:close-ended';

    /** @var string */
    protected $description = 'Close the universes whose season has ended';

    public function handle(InstanceRegistry $registry, InstanceProvisioner $provisioner, Versions $versions): int
    {
        $now = time();
        $failed = false;

        foreach ($registry->all() as $instance) {
            if ($instance->status !== Instance::OPEN || !$instance->hasEnded($now)) {
                continue;
            }

            try {
                // the rankings are frozen by the code the universe plays on
                $versions->runFor($instance, 'xgp:instance:update', [$instance->slug, '--status=closed'], static fn (): Instance => $provisioner->close($instance));
                $this->info('Closed ' . $instance->slug . '.');
            } catch (Throwable $e) {
                report($e);
                $this->error($instance->slug . ': ' . $e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
