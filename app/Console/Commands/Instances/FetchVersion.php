<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\VersionFetcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * A new version of the code from git into a folder of its own under the
 * versions path (a server without Docker); xgp:versions:use then serves it.
 */
class FetchVersion extends Command
{
    /** @var string */
    protected $signature = 'xgp:versions:fetch
        {ref? : the tag, branch or commit to fetch, default: the newest tag}
        {--repo= : the repository, default: the origin of this checkout}';

    /** @var string */
    protected $description = 'Fetch a new version of the code (tag, branch or commit) into a folder of its own';

    public function handle(VersionFetcher $fetcher): int
    {
        $ref = $this->argument('ref');
        $repository = $this->option('repo');

        try {
            foreach ($fetcher->fetch(is_string($ref) && $ref !== '' ? $ref : null, is_string($repository) && $repository !== '' ? $repository : null) as $line) {
                $this->info($line);
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
