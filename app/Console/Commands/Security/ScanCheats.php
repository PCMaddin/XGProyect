<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\Versions;
use App\Services\Security\CheatScanner;
use App\Services\Security\PlayerTrail;
use App\Services\Security\Suspicions;
use Illuminate\Console\Command;
use Throwable;

/**
 * Looks for signs of multiple accounts, pushing and scripts in every open
 * universe (or the one game) and lists them for the admin; deletes old
 * sign-ins and activity. The scheduler runs it every hour.
 */
class ScanCheats extends Command
{
    /** @var string */
    protected $signature = 'xgp:cheat:scan {slug? : one universe (how a universe on another version is scanned)}';

    /** @var string */
    protected $description = 'Look for signs of cheating and list them for the admin';

    public function handle(InstanceRegistry $registry, InstanceContext $context, Versions $versions): int
    {
        $now = time();
        $work = static function () use ($now): int {
            $keep = config('cheats.keep_days');
            $before = $now - (is_int($keep) ? $keep : 60) * 86400;
            app(PlayerTrail::class)->prune($before);
            app(Suspicions::class)->prune($before);

            return app(CheatScanner::class)->scan($now);
        };

        if (!$registry->enabled()) {
            $this->info('Signs: ' . $work());

            return self::SUCCESS;
        }

        $failed = false;
        $slug = $this->argument('slug');
        $instances = is_string($slug) ? array_filter([$registry->find($slug)], static fn (?Instance $instance): bool => $instance?->isOpen() ?? false) : $registry->open();

        foreach ($instances as $instance) {
            try {
                $this->info($instance->slug . ': ' . trim($versions->runFor($instance, 'xgp:cheat:scan', [$instance->slug], fn (): string => (string) $context->runIn($instance, $work))));
            } catch (Throwable $e) {
                report($e);
                $this->error($instance->slug . ': ' . $e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
