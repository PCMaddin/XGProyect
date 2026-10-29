<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Services\Operators\LoadTestPlayers;
use App\Services\Operators\LoadTestUniverse;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Deletes the players of xgp:loadtest:seed.
 */
class ClearLoadTest extends Command
{
    /** @var string */
    protected $signature = 'xgp:loadtest:clear {--universe= : the universe of the test (with several universes)}';

    /** @var string */
    protected $description = 'Delete the players of a load test';

    public function handle(LoadTestUniverse $universe): int
    {
        try {
            $deleted = $universe->run($this->option('universe'), function (): int {
                $players = app(LoadTestPlayers::class);
                $bar = $this->output->createProgressBar($players->count());
                $deleted = $players->clear(static fn (int $step) => $bar->advance($step));
                $bar->finish();
                $this->newLine();

                return $deleted;
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($deleted . ' test players deleted.');

        return self::SUCCESS;
    }
}
