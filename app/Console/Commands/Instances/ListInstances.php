<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\Instance;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Console\Command;

class ListInstances extends Command
{
    /** @var string */
    protected $signature = 'xgp:instance:list';

    /** @var string */
    protected $description = 'List the universes of the lobby';

    public function handle(InstanceRegistry $registry): int
    {
        $instances = $registry->all();

        if ($instances === []) {
            $this->info('No universe registered: the game is a single universe.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Name', 'Database', 'Mode', 'Status', 'Opened'],
            array_map(static fn (Instance $instance): array => [
                $instance->slug,
                $instance->name,
                $instance->database,
                $instance->mode,
                $instance->status,
                date('Y-m-d H:i', $instance->openedAt),
            ], $instances)
        );

        return self::SUCCESS;
    }
}
