<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\VersionFetcher;
use App\Services\Instances\Versions;
use Illuminate\Console\Command;
use Throwable;

/**
 * Serves a version that lies under the versions path: the folder `current`
 * (the web root is current/public) points at it from now on; the version's
 * own code writes the router's map.
 */
class UseVersion extends Command
{
    /** @var string */
    protected $signature = 'xgp:versions:use {version : a version on the server (xgp:versions)}';

    /** @var string */
    protected $description = 'Point the folder "current" of the versions path at a version';

    public function handle(VersionFetcher $fetcher, Versions $versions): int
    {
        $version = (string) $this->argument('version');

        try {
            $directory = $fetcher->use($version);
            $this->line(trim($versions->artisan($version, 'xgp:versions:sync')));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($versions->root() . '/' . VersionFetcher::CURRENT . ' -> ' . $directory . '. The web root ' . $versions->root() . '/' . VersionFetcher::CURRENT . '/public and the scheduler (current/artisan) now serve ' . $version . '.');

        return self::SUCCESS;
    }
}
