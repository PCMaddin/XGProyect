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
 * Moves a universe to a newer version of the code: its database gets that
 * version's update migrations, then its pages, tick and scans come from
 * that version. Never back to an older one (the migrations do not go
 * back). The newest on the server unless --to names one in between, which
 * then does the work with its own code.
 */
class UpgradeInstance extends Command
{
    /** @var string */
    protected $signature = 'xgp:instance:upgrade
        {slug}
        {--to= : the version to move to, default: the one that runs (xgp:versions lists them)}';

    /** @var string */
    protected $description = 'Move a universe to a newer version of the code';

    public function handle(InstanceRegistry $registry, InstanceProvisioner $provisioner, Versions $versions): int
    {
        $provisioner->prepareRegistry();
        $instance = $registry->find((string) $this->argument('slug'));

        if ($instance === null) {
            $this->error('There is no such universe (xgp:instance:list).');

            return self::FAILURE;
        }

        $to = $this->option('to');
        $to = is_string($to) && $to !== '' ? $to : $versions->current();
        $from = $instance->version !== '' ? $instance->version : $versions->current();

        $refusal = self::refusal($instance, $from, $to, $versions);

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        if ($to === $from) {
            $this->info('The universe ' . $instance->slug . ' plays on ' . $to . ' already.');

            return self::SUCCESS;
        }

        try {
            if ($to !== $versions->current()) {
                // a version in between: its own code migrates and pins
                $this->line(trim($versions->artisan($to, 'xgp:instance:upgrade', [$instance->slug])));
                $versions->writeMap();

                return self::SUCCESS;
            }

            $this->line(trim($provisioner->migrate($instance)));
            $registry->update($instance->slug, ['version' => $to]);
            $versions->writeMap();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Universe ' . $instance->slug . ' now plays on version ' . $to . ' (before: ' . $from . ').');

        return self::SUCCESS;
    }

    private static function refusal(Instance $instance, string $from, string $to, Versions $versions): ?string
    {
        if (!$instance->hasDatabase()) {
            return 'The universe ' . $instance->slug . ' is archived: bring it back first (xgp:instance:unarchive).';
        }

        if (version_compare($to, $from, '<')) {
            return 'The universe ' . $instance->slug . ' plays on ' . $from . '; ' . $to . ' is older, and migrations do not go back.';
        }

        if ($versions->directory($to) === null) {
            return 'The version ' . $to . ' is not on the server (xgp:versions).';
        }

        return null;
    }
}
