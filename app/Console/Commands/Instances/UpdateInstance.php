<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Console\Command;
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
        {--ends= : when the season ends (a date, e.g. 2026-12-01 or "+2 weeks"), or none}';

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
                $this->error('Not a date: ' . $ends);

                return self::FAILURE;
            }

            $changes['ends_at'] = $time;
        }

        try {
            /** @var array{name?: string, mode?: string, status?: string, payments?: string, ends_at?: int|null} $changes */
            $changed = $provisioner->change($instance, $changes);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Universe ' . $changed->slug . ': ' . $changed->name . ', ' . $changed->mode . ', ' . $changed->status
            . ($changed->endsAt === null ? '' : ', ends ' . date('Y-m-d H:i', $changed->endsAt)) . '.');

        return self::SUCCESS;
    }
}
