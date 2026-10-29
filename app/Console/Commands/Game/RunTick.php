<?php

declare(strict_types=1);

namespace App\Console\Commands\Game;

use App\Libraries\Functions;
use App\Libraries\UpdatesLibrary;
use App\Services\Game\GameBootstrap;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\ProcessPool;
use App\Services\Instances\Versions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * The game tick of every open universe, from the scheduler (every minute):
 * fleets, relocations, auctions, and the work no page view should wait for,
 * statistics, cleanup, backups and bots. While it runs, page views leave
 * that work to it; a universe whose tick fails does not stop the others.
 *
 * Several universes tick side by side, each in a process of its own (at
 * most XGP_TICK_PARALLEL at a time), with the code of its version: one
 * busy with its statistics holds up no other. A universe whose tick still
 * runs from the minute before is left alone (a lock per universe).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class RunTick extends Command
{
    /** a tick that dies keeps its universe locked no longer than this */
    private const LOCK_SECONDS = 900;

    /** @var string */
    protected $signature = 'xgp:tick {slug? : one universe (how each universe is ticked in a process of its own)}';

    /** @var string */
    protected $description = 'Run the game tick of every open universe (fleets, statistics, cleanup, bots)';

    public function handle(InstanceRegistry $registry, InstanceContext $context, Versions $versions, ProcessPool $pool): int
    {
        if (!$registry->enabled()) {
            $this->info(self::locked('xgp_tick', static fn (): string => self::work()));

            return self::SUCCESS;
        }

        $slug = $this->argument('slug');
        // one universe (a process of the parallel tick), or all open ones
        $instances = is_string($slug)
            ? array_filter([$registry->find($slug)], static fn (?Instance $instance): bool => $instance?->isOpen() ?? false)
            : $registry->open();
        $parallel = config('instances.tick_parallel');
        $parallel = is_int($parallel) && !is_string($slug) ? $parallel : 1;

        return $parallel < 2 || count($instances) < 2
            ? $this->oneByOne($instances, $context, $versions)
            : $this->side($instances, $versions, $pool, $parallel);
    }

    /**
     * @param list<Instance> $instances
     */
    private function oneByOne(array $instances, InstanceContext $context, Versions $versions): int
    {
        $failed = false;

        foreach ($instances as $instance) {
            $failed = !$this->tick($instance, $context, $versions) || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every universe in a process of its own, with the code of its version.
     *
     * @param list<Instance> $open
     */
    private function side(array $open, Versions $versions, ProcessPool $pool, int $parallel): int
    {
        $jobs = [];
        $failed = false;

        foreach ($open as $instance) {
            try {
                $jobs[$instance->slug] = $versions->process($instance->version, 'xgp:tick', [$instance->slug]);
            } catch (Throwable $e) {
                report($e);
                $this->error($instance->slug . ': ' . $e->getMessage());
                $failed = true;
            }
        }

        foreach ($pool->run($jobs, $parallel) as $slug => [$ok, $output]) {
            if ($ok) {
                $this->info($output);

                continue;
            }

            report(new RuntimeException('The tick of ' . $slug . ' failed: ' . $output));
            $this->error($slug . ': ' . $output);
            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One universe, here or with the code of its version (which locks it).
     */
    private function tick(Instance $instance, InstanceContext $context, Versions $versions): bool
    {
        try {
            $output = $instance->runsOn($versions->current())
                ? self::locked('xgp_tick_' . $instance->slug, static fn (): string => $context->runIn($instance, static fn (): string => self::work()))
                : trim($versions->artisan($instance->version, 'xgp:tick', [$instance->slug]));
        } catch (Throwable $e) {
            report($e);
            $this->error($instance->slug . ': ' . $e->getMessage());

            return false;
        }

        // a version's own output names the universe already
        $this->info(str_starts_with($output, $instance->slug . ':') ? $output : $instance->slug . ': ' . $output);

        return true;
    }

    /**
     * @param callable(): string $work
     */
    private static function locked(string $name, callable $work): string
    {
        $lock = Cache::lock($name, self::LOCK_SECONDS);

        if (!$lock->get()) {
            return 'busy (the tick from before still runs)';
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    private static function work(): string
    {
        Functions::setLanguage();
        app(GameBootstrap::class)->applyTimezone();
        new UpdatesLibrary(time());

        return 'ok';
    }
}
