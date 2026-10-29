<?php

declare(strict_types=1);

namespace Tests\Unit\App\Console;

use App\Console\Commands\MarkInstalledUpdates;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The update migrations an installation already contains are recorded as
 * run: without --all the older ones (the later ones check what they change),
 * with --all (installer) every one; nothing twice.
 */
#[CoversClass(MarkInstalledUpdates::class)]
class MarkInstalledUpdatesTest extends DatabaseTestCase
{
    public function testTheOlderUpdatesAreRecordedOnce(): void
    {
        $this->assertSame(0, Artisan::call('xgp:mark-installed-updates'));
        $this->assertSame(0, Artisan::call('xgp:mark-installed-updates'));

        $recorded = $this->recorded();

        $this->assertContains('2023_06_18_170953_migrate_banned_to_bans', $recorded);
        $this->assertNotContains('2026_10_16_000003_add_merchant_offer_to_premium', $recorded);
        $this->assertSame(count($recorded), count(array_unique($recorded)));
    }

    public function testTheInstallerRecordsAllOfThem(): void
    {
        $this->assertSame(0, Artisan::call('xgp:mark-installed-updates', ['--all' => true]));

        $files = array_map(fn (string $file): string => basename($file, '.php'), glob(database_path('migrations/400/*.php')) ?: []);

        $this->assertSame([], array_values(array_diff($files, $this->recorded())));
    }

    /**
     * @return list<string>
     */
    private function recorded(): array
    {
        return array_values(array_map(
            fn (mixed $name): string => is_string($name) ? $name : '',
            DB::table('migrations')->pluck('migration')->all()
        ));
    }
}
