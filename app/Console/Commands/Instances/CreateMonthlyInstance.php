<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\Instance;
use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * The universe of the month, as config/instances.php describes it. Runs
 * again without harm: a month's universe is created once. Optionally
 * closes the older universes, keeping the newest open.
 */
class CreateMonthlyInstance extends Command
{
    /** @var string */
    protected $signature = 'xgp:instance:monthly {--date= : the month to open (Y-m-d), default: now}';

    /** @var string */
    protected $description = 'Open the universe of the month';

    public function handle(InstanceProvisioner $provisioner, InstanceRegistry $registry): int
    {
        $date = $this->option('date');
        $time = is_string($date) && $date !== '' ? strtotime($date) : time();

        if ($time === false) {
            $this->error('Not a date: ' . (is_string($date) ? $date : ''));

            return self::FAILURE;
        }

        $provisioner->prepareRegistry();
        $placeholders = [':year' => date('Y', $time), ':month' => date('m', $time), ':number' => (string) (count($registry->all()) + 1)];
        $slug = strtr(self::config('slug'), $placeholders);

        if ($registry->find($slug) !== null) {
            $this->info('The universe ' . $slug . ' exists already.');

            return self::SUCCESS;
        }

        // the season runs until the next one opens, unless configured otherwise
        $duration = self::config('duration');
        $ends = $duration === '' ? null : strtotime('+' . ltrim($duration, '+'), $time);
        $instance = new Instance($slug, strtr(self::config('name'), $placeholders), $provisioner->databaseFor($slug), self::config('mode'), Instance::OPEN, $time, $ends === false ? null : $ends);

        try {
            $options = ['payment_methods' => $provisioner->paymentMethods(self::config('payments'))] + $this->universeOptions();
            $provisioner->create($instance, self::config('admin.name'), self::config('admin.email'), self::config('admin.password'), $options, max(0, (int) self::config('bots')));
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Universe ' . $instance->slug . ' (' . $instance->mode . ') is open.');
        $this->closeOlder($provisioner, $registry);

        return self::SUCCESS;
    }

    /**
     * All but the newest keep_open universes are closed.
     */
    private function closeOlder(InstanceProvisioner $provisioner, InstanceRegistry $registry): void
    {
        $keep = config('instances.monthly.keep_open');

        if (!is_int($keep) || $keep < 1) {
            return;
        }

        // newest first
        foreach (array_slice($registry->open(), $keep) as $instance) {
            $provisioner->close($instance);
            $this->info('Closed ' . $instance->slug . '.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function universeOptions(): array
    {
        $options = [];

        foreach (explode(',', self::config('options')) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            if ($name !== '') {
                $options[$name] = $value;
            }
        }

        return $options;
    }

    private static function config(string $key): string
    {
        $value = config('instances.monthly.' . $key);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
