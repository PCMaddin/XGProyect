<?php

declare(strict_types=1);

namespace App\Console\Commands\Game;

use App\Libraries\Functions;
use App\Libraries\UpdatesLibrary;
use App\Services\Game\GameBootstrap;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\Versions;
use Illuminate\Console\Command;
use Throwable;

/**
 * The game tick of every open universe, from the scheduler (every minute):
 * fleets, relocations, auctions, and the work no page view should wait for,
 * statistics, cleanup, backups and bots. While it runs, page views leave
 * that work to it; a universe whose tick fails does not stop the others.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class RunTick extends Command
{
    /** @var string */
    protected $signature = 'xgp:tick {slug? : one universe (how a universe on another version is ticked)}';

    /** @var string */
    protected $description = 'Run the game tick of every open universe (fleets, statistics, cleanup, bots)';

    public function handle(InstanceRegistry $registry, InstanceContext $context, Versions $versions): int
    {
        $work = static function (): string {
            Functions::setLanguage();
            app(GameBootstrap::class)->applyTimezone();
            new UpdatesLibrary(time());

            return 'ok';
        };

        if (!$registry->enabled()) {
            $this->info($work());

            return self::SUCCESS;
        }

        $failed = false;
        $slug = $this->argument('slug');
        $instances = is_string($slug) ? array_filter([$registry->find($slug)], static fn (?Instance $instance): bool => $instance?->isOpen() ?? false) : $registry->open();

        foreach ($instances as $instance) {
            try {
                // a universe on another version is ticked by that version's code
                $this->info($instance->slug . ': ' . trim($versions->runFor($instance, 'xgp:tick', [$instance->slug], fn (): string => $context->runIn($instance, $work))));
            } catch (Throwable $e) {
                report($e);
                $this->error($instance->slug . ': ' . $e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
