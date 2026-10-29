<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Services\Operators\OffsiteBackup;
use Illuminate\Console\Command;
use RuntimeException;

class ListBackups extends Command
{
    /** @var string */
    protected $signature = 'xgp:backup:list {database? : only this database}';

    /** @var string */
    protected $description = 'List the backups on the other server';

    public function handle(OffsiteBackup $backup): int
    {
        try {
            $snapshots = $backup->snapshots();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $database = $this->argument('database');
        $rows = array_filter($snapshots, static fn (array $snapshot): bool => !is_string($database) || $snapshot['database'] === $database);

        $this->table(['Snapshot', 'Time', 'Database'], array_map(static fn (array $snapshot): array => [$snapshot['id'], $snapshot['time'], $snapshot['database']], $rows));

        return self::SUCCESS;
    }
}
