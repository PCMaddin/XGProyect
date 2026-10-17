<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\PlayerTextCleanup;
use Illuminate\Console\Command;

/**
 * Cleans player names, alliance names, tags, addresses and rank names and
 * planet names stored before the XSS fixes. Run once after updating.
 */
class CleanPlayerTexts extends Command
{
    /** @var string */
    protected $signature = 'xgp:clean-player-texts {--dry-run : only list what would change}';

    /** @var string */
    protected $description = 'Clean player texts stored before the XSS fixes (names, tags, addresses, rank and planet names)';

    public function handle(PlayerTextCleanup $cleanup): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changes = $cleanup->run($dryRun);

        if ($changes === []) {
            $this->info('Nothing to clean.');

            return self::SUCCESS;
        }

        $this->table(
            ['Table', 'Id', 'Column', 'From', 'To'],
            array_map(fn (array $change): array => array_values($change), $changes)
        );
        $this->info(($dryRun ? 'Would change ' : 'Changed ') . count($changes) . ' value(s).');

        return self::SUCCESS;
    }
}
