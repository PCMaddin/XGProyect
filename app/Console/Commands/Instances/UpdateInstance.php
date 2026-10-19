<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\Instance;
use App\Services\Instances\DatabaseSwitch;
use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Renames a universe, switches it between pay2win and play2win, or closes
 * it (the lobby lists it as ended, nobody plays in it any more).
 */
class UpdateInstance extends Command
{
    /** @var string */
    protected $signature = 'xgp:instance:update
        {slug}
        {--name=}
        {--mode= : pay2win or play2win}
        {--status= : open or closed}
        {--payments= : ways to pay in pay2win: stripe, wax, stripe,wax or none}
        {--ends= : when the season ends (a date, e.g. 2026-12-01 or "+2 weeks"), or none}
        {--rewards= : dark matter for the best ten per ranking, e.g. total=100000,military=20000}
        {--shares= : percent of a ranking\'s dark matter for places 1, 2, 3 … e.g. 30,20,15,10,7,6,4,3,3,2}
        {--database= : play on this database from now on, e.g. one restored with xgp:backup:restore}';

    /** @var string */
    protected $description = 'Change the name, mode or status of a universe';

    public function handle(InstanceRegistry $registry, InstanceProvisioner $provisioner): int
    {
        $slug = $this->argument('slug');
        $instance = $registry->find($slug);

        if ($instance === null) {
            $this->error('No universe ' . $slug . '.');

            return self::FAILURE;
        }

        try {
            $database = $this->option('database');

            if (is_string($database) && $database !== '') {
                app(DatabaseSwitch::class)->switch($instance, $database);
                $this->info('Universe ' . $instance->slug . ' now plays on the database ' . $database . '; ' . $instance->database . ' is kept.');
            }

            $changed = $provisioner->change($instance, $this->changes());
            $this->changeRewards($provisioner, $changed);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Universe ' . $changed->slug . ': ' . $changed->name . ', ' . $changed->mode . ', ' . $changed->status
            . ($changed->endsAt === null ? '' : ', ends ' . date('Y-m-d H:i', $changed->endsAt)) . '.');

        return self::SUCCESS;
    }

    /**
     * @return array{name?: string, mode?: string, status?: string, payments?: string, ends_at?: int|null}
     *
     * @throws InvalidArgumentException for a date that is none
     */
    private function changes(): array
    {
        /** @var array{name?: string, mode?: string, status?: string} $changes */
        $changes = array_filter(
            ['name' => $this->option('name'), 'mode' => $this->option('mode'), 'status' => $this->option('status')],
            static fn (mixed $value): bool => is_string($value) && $value !== ''
        );
        $payments = $this->option('payments');

        if (is_string($payments)) {
            $changes['payments'] = $payments === 'none' ? '' : $payments;
        }

        $ends = $this->option('ends');

        if (is_string($ends) && $ends !== '') {
            $time = $ends === 'none' ? null : strtotime($ends);

            if ($time === false) {
                throw new InvalidArgumentException('Not a date: ' . $ends);
            }

            $changes['ends_at'] = $time;
        }

        return $changes;
    }

    private function changeRewards(InstanceProvisioner $provisioner, Instance $instance): void
    {
        $rewards = $this->option('rewards');
        $shares = $this->option('shares');

        if (!is_string($rewards) && !is_string($shares)) {
            return;
        }

        $plan = $provisioner->changeRewards($instance, is_string($rewards) ? $rewards : null, is_string($shares) ? $shares : null);
        $this->info('Rewards: ' . json_encode($plan->pools) . ', shares: ' . implode('/', $plan->shares) . ' %.');
    }
}
