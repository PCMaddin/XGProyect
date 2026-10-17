<?php

declare(strict_types=1);

namespace App\Services\Instances;

use Illuminate\Cache\CacheManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * The universe this request or job plays in: its database becomes the
 * game's connection, and the cache gets a prefix of its own, so nothing is
 * shared between universes but the registry.
 */
class InstanceContext
{
    private ?Instance $current = null;

    private ?string $baseDatabase = null;

    private ?string $baseCachePrefix = null;

    public function __construct(private Application $app, private CacheManager $cache)
    {
    }

    public function current(): ?Instance
    {
        return $this->current;
    }

    public function activate(Instance $instance): void
    {
        $connection = $this->gameConnection();
        $this->baseDatabase ??= self::str(config('database.connections.' . $connection . '.database'));
        $this->baseCachePrefix ??= self::str(config('cache.prefix'));

        config([
            'database.connections.' . $connection . '.database' => $instance->database,
            'cache.prefix' => $this->baseCachePrefix . '_' . $instance->slug,
        ]);
        DB::purge($connection);
        $this->cache->forgetDriver($this->cache->getDefaultDriver());
        // options and other per-request services were read from the base database
        $this->app->forgetScopedInstances();
        $this->current = $instance;
    }

    /**
     * Run a piece of work in another universe, then come back.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function runIn(Instance $instance, callable $work): mixed
    {
        $previous = $this->current;
        $this->activate($instance);

        try {
            return $work();
        } finally {
            $previous === null ? $this->reset() : $this->activate($previous);
        }
    }

    /**
     * Back to the base database (a single universe).
     */
    public function reset(): void
    {
        if ($this->current === null) {
            return;
        }

        $connection = $this->gameConnection();
        config([
            'database.connections.' . $connection . '.database' => $this->baseDatabase,
            'cache.prefix' => $this->baseCachePrefix,
        ]);
        DB::purge($connection);
        $this->cache->forgetDriver($this->cache->getDefaultDriver());
        $this->app->forgetScopedInstances();
        $this->current = null;
    }

    private function gameConnection(): string
    {
        return self::str(config('database.default'));
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
