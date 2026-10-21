<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\Versions;
use App\Services\Instances\UniverseArchive;
use App\Services\Operators\OperatorLog;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Brings an archived universe's database back and brings it up to the
 * current version (migrations); the universe is closed again
 * (xgp:instance:update --status=open opens it).
 */
class UnarchiveInstance extends Command
{
    /** @var string */
    protected $signature = 'xgp:instance:unarchive {slug : the archived universe}';

    /** @var string */
    protected $description = 'Restore the database of an archived universe';

    public function handle(InstanceRegistry $registry, InstanceProvisioner $provisioner, UniverseArchive $archive, OperatorLog $log, Versions $versions): int
    {
        $instance = $registry->find((string) $this->argument('slug'));

        if ($instance === null) {
            $this->error('There is no such universe (xgp:instance:list).');

            return self::FAILURE;
        }

        try {
            $archive->unarchive($instance);
            // migrated by the code it plays on, never by a newer one
            $this->line(trim($versions->runFor($instance, 'xgp:instance:migrate', [$instance->slug], static fn (): string => $provisioner->migrate($instance))));
            $versions->writeMap();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $log->record(null, 'cli', OperatorLog::UNARCHIVED, 'cli', $instance->slug, ['archive' => $instance->archive]);
        $this->info($instance->slug . ': database ' . $instance->database . ' is back, the universe is closed.');

        return self::SUCCESS;
    }
}
