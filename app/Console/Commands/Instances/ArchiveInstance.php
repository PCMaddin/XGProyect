<?php

declare(strict_types=1);

namespace App\Console\Commands\Instances;

use App\Services\Instances\Instance;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\UniverseArchive;
use App\Services\Operators\OperatorLog;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Archives closed universes: database dumped and checked, then dropped
 * (UniverseArchive). By name, or --due: every one closed for longer than
 * XGP_ARCHIVE_AFTER_DAYS (the scheduler runs that every night).
 */
class ArchiveInstance extends Command
{
    /** @var string */
    protected $signature = 'xgp:instance:archive
        {slug? : the closed universe}
        {--due : every universe closed for longer than XGP_ARCHIVE_AFTER_DAYS}
        {--file= : a new .sql.gz file instead of the backup server}';

    /** @var string */
    protected $description = 'Dump a closed universe for good and drop its database';

    public function handle(InstanceRegistry $registry, UniverseArchive $archive, OperatorLog $log): int
    {
        $now = time();
        $slug = $this->argument('slug');
        $file = $this->option('file');

        $instances = $this->option('due') ? $archive->due($now) : array_filter([is_string($slug) ? $registry->find($slug) : null]);

        if ($instances === [] && !$this->option('due')) {
            $this->error('Name a universe (xgp:instance:list) or give --due.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($instances as $instance) {
            $failed = !$this->archiveOne($archive, $log, $instance, $now, is_string($file) && $file !== '' ? $file : null) || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function archiveOne(UniverseArchive $archive, OperatorLog $log, Instance $instance, int $now, ?string $file): bool
    {
        try {
            $where = $archive->archive($instance, $now, $file);
        } catch (RuntimeException $e) {
            report($e);
            $this->error($instance->slug . ': ' . $e->getMessage());

            return false;
        }

        $log->record(null, 'cli', OperatorLog::ARCHIVED, 'cli', $instance->slug, ['database' => $instance->database, 'archive' => $where]);
        $this->info($instance->slug . ': archived (' . $where . '), database ' . $instance->database . ' dropped.');

        return true;
    }
}
