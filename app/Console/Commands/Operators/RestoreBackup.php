<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Services\Operators\OffsiteBackup;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Brings a database back from the other server into a new database; the
 * running universe stays as it is until you switch it over (see
 * docs/universes.md).
 */
class RestoreBackup extends Command
{
    /** @var string */
    protected $signature = 'xgp:backup:restore
        {database : the database backed up, e.g. xgp_uni_2026_11}
        {--into= : the new database (default: <database>_restore_<date>)}
        {--snapshot=latest : a snapshot from xgp:backup:list}';

    /** @var string */
    protected $description = 'Restore a backed-up database into a new database';

    public function handle(OffsiteBackup $backup): int
    {
        $database = (string) $this->argument('database');
        $into = $this->option('into');
        $into = is_string($into) && $into !== '' ? $into : $database . '_restore_' . date('Ymd_His');
        $snapshot = $this->option('snapshot');

        try {
            $backup->restore($database, $into, is_string($snapshot) ? $snapshot : 'latest');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Restored ' . $database . ' into ' . $into . '.');

        return self::SUCCESS;
    }
}
