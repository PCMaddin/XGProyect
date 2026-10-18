<?php

declare(strict_types=1);

namespace App\Services\Instances;

use Illuminate\Cache\CacheManager;
use Illuminate\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\DB;

/**
 * The universe this request or job plays in: its database becomes the
 * game's connection, and the cache, the backups and the error log get names
 * of their own (the cache prefix, the backup folder, xgproyect-<slug>.log),
 * so nothing is shared between universes but the registry.
 */
class InstanceContext
{
    /** the query parameter that names the universe in links sent by mail */
    public const QUERY = 'universe';

    private ?Instance $current = null;

    private ?string $baseDatabase = null;

    /** settings that get the universe's slug appended (log files before .log) */
    private const PER_UNIVERSE = ['cache.prefix', 'backup.backup.name', 'logging.channels.single.path', 'logging.channels.daily.path'];

    /** log channels that write the files above */
    private const LOG_CHANNELS = ['stack', 'single', 'daily'];

    /** @var array<string, string> their values for the base database */
    private array $base = [];

    public function __construct(private Application $app, private CacheManager $cache, private LogManager $log)
    {
    }

    public function current(): ?Instance
    {
        return $this->current;
    }

    /**
     * A link into this universe that also works in a browser that never
     * chose it in the lobby (links sent by mail).
     */
    public function link(string $url): string
    {
        if ($this->current === null) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . self::QUERY . '=' . rawurlencode($this->current->slug);
    }

    public function activate(Instance $instance): void
    {
        $connection = $this->gameConnection();
        $this->baseDatabase ??= self::str(config('database.connections.' . $connection . '.database'));
        $settings = ['database.connections.' . $connection . '.database' => $instance->database];

        foreach (self::PER_UNIVERSE as $key) {
            $this->base[$key] ??= self::str(config($key));
            $settings[$key] = self::suffixed($this->base[$key], $instance->slug);
        }

        config($settings);
        $this->forgetLogChannels();
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
        config(['database.connections.' . $connection . '.database' => $this->baseDatabase] + $this->base);
        $this->forgetLogChannels();
        DB::purge($connection);
        $this->cache->forgetDriver($this->cache->getDefaultDriver());
        $this->app->forgetScopedInstances();
        $this->current = null;
    }

    /**
     * "xgp" → "xgp_andromeda", ".../xgproyect.log" → ".../xgproyect-andromeda.log".
     */
    private static function suffixed(string $value, string $slug): string
    {
        return str_ends_with($value, '.log') ? substr($value, 0, -4) . '-' . $slug . '.log' : $value . '_' . $slug;
    }

    private function forgetLogChannels(): void
    {
        foreach (self::LOG_CHANNELS as $channel) {
            $this->log->forgetChannel($channel);
        }
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
