<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Services\Operators\LoadTestPlayers;
use App\Services\Operators\LoadTestUniverse;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Fills a universe of its own with players for xgp:loadtest:run.
 */
class SeedLoadTest extends Command
{
    /** @var string */
    protected $signature = 'xgp:loadtest:seed
        {--universe= : the universe for the test (with several universes)}
        {--players=1000 : how many test players the universe should have}
        {--password= : their password (at least 12 characters), for xgp:loadtest:run}';

    /** @var string */
    protected $description = 'Create players for a load test in an empty universe';

    public function handle(LoadTestUniverse $universe): int
    {
        $password = $this->option('password');
        $players = (int) $this->option('players');

        if (!is_string($password) || mb_strlen($password) < 12) {
            $this->error('Give the players a password of at least 12 characters: --password=…');

            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar(max(0, $players));

        try {
            $message = $universe->run($this->option('universe'), static function () use ($players, $password, $bar): string {
                $seeder = app(LoadTestPlayers::class);
                $bar->setProgress(min($players, $seeder->count()));
                $added = $seeder->seed($players, $password, time(), static fn (int $step) => $bar->advance($step));
                $bar->finish();

                return $added . ' players added, ' . $seeder->count() . ' in all (load1@' . LoadTestPlayers::DOMAIN . ' …).';
            });
        } catch (RuntimeException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($message);
        $this->line('Sign-ups in this universe are now by invitation only. Remove the players afterwards: xgp:loadtest:clear.');

        return self::SUCCESS;
    }
}
