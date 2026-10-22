<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\Versions;
use Illuminate\Console\Command;
use Throwable;

/**
 * After an update: the running code into its directory under the versions
 * path (once per version), and the router's map of the universes on other
 * versions. The Docker stack runs it at every container start.
 */
class SyncVersions extends Command
{
    /** @var string */
    protected $signature = 'xgp:versions:sync';

    /** @var string */
    protected $description = 'Keep the running version of the code on the server and write the router map';

    public function handle(Versions $versions, InstanceProvisioner $provisioner): int
    {
        try {
            $provisioner->prepareRegistry();

            foreach ($versions->sync() as $line) {
                str_starts_with($line, 'WARNING') ? $this->warn($line) : $this->info($line);
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
