<?php

declare(strict_types=1);

namespace App\Services\Instances;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\DB;

/**
 * The universe this request or job plays in: its database becomes the
 * game's connection, and the cache, the backups and the error log get names
 * of their own (the cache prefix or folder, the backup folder,
 * xgproyect-<slug>.log),
 * so nothing is shared between universes but the registry. Sessions live in
 * the universe's database or, on Redis, under its cache prefix: a session
 * of one universe is unknown in the others (player 5 there is someone else).
 */
class InstanceContext
{
    /** the query parameter that names the universe in links sent by mail */
    public const QUERY = 'universe';

    private ?Instance $current = null;

    private ?string $baseDatabase = null;

    /**
     * settings that get the universe's slug appended (log files before
     * .log); the cache in files knows no prefix, it gets a folder of its own
     */
    private const PER_UNIVERSE = ['cache.prefix', 'cache.stores.file.path', 'backup.backup.name', 'logging.channels.single.path', 'logging.channels.daily.path'];

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
            $settings[$key] = self::suffixed($key, $this->base[$key], $instance->slug);
        }

        config($settings);
        $this->forgetLogChannels();
        DB::purge($connection);
        $this->forgetStores();
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
        $this->forgetStores();
        $this->app->forgetScopedInstances();
        $this->current = null;
    }

    /**
     * "xgp" → "xgp_andromeda", ".../xgproyect.log" → ".../xgproyect-andromeda.log";
     * the cache prefix ends after the slug, so the key "1x" of "u1" is not
     * the key "x" of "u11".
     */
    private static function suffixed(string $key, string $value, string $slug): string
    {
        if (str_ends_with($value, '.log')) {
            return substr($value, 0, -4) . '-' . $slug . '.log';
        }

        return $value . '_' . $slug . ($key === 'cache.prefix' ? ':' : '');
    }

    /**
     * The cache and the sessions are built again with the prefix and the
     * connection of the universe now active. A session already started
     * (a page that looks into other universes, like the lobby) is kept: it
     * belongs to the request, and its token is in the page's forms.
     */
    private function forgetStores(): void
    {
        $session = config('session.store') ?: config('session.driver');
        $this->cache->forgetDriver(array_values(array_unique([$this->cache->getDefaultDriver(), self::str($session)])));

        if (!$this->app->resolved('session')) {
            return;
        }

        $sessions = $this->app->make('session');

        foreach ($sessions->getDrivers() as $store) {
            if ($store instanceof Session && $store->isStarted()) {
                return;
            }
        }

        $sessions->forgetDrivers();
        // what the guard reads the logged-in player from
        $this->app->forgetInstance('session.store');
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
