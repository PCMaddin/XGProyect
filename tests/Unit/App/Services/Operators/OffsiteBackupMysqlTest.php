<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Operators;

use App\Console\Commands\Operators\RestoreBackup;
use App\Console\Commands\Operators\RunBackup;
use App\Services\Operators\OffsiteBackup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Tests\MysqlTestCase;

/**
 * The restore drill as a test: the base database goes into a restic
 * repository, gets damaged, and comes back into a new database with every
 * table as it was; a restore never overwrites a database, and a wrong
 * password fails the run (recorded). Needs restic and mariadb-dump.
 */
#[CoversClass(OffsiteBackup::class)]
#[CoversClass(RunBackup::class)]
#[CoversClass(RestoreBackup::class)]
class OffsiteBackupMysqlTest extends MysqlTestCase
{
    private const RESTORED = 'xgp_test_restored';

    private string $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $finder = new ExecutableFinder();

        if ($finder->find('restic') === null || $finder->find('mariadb-dump') === null) {
            $this->markTestSkipped('restic and mariadb-dump are needed.');
        }

        $this->repository = sys_get_temp_dir() . '/xgp-restic-' . getmypid();
        File::deleteDirectory($this->repository);
        config([
            'database.connections.central' => config('database.connections.mysql_test'),
            'offsite.repository' => $this->repository,
            'offsite.password' => 'test-password',
        ]);
        DB::purge('central');
        DB::connection('central')->statement('DROP DATABASE IF EXISTS `' . self::RESTORED . '`');
        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        DB::table('users')->insert([
            ['name' => 'First', 'email' => 'first@example.com', 'password' => 'x', 'home_planet_id' => 1, 'current_planet' => 1],
            ['name' => 'Second', 'email' => 'second@example.com', 'password' => 'x', 'home_planet_id' => 2, 'current_planet' => 2],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->repository)) {
            DB::connection('central')->statement('DROP DATABASE IF EXISTS `' . self::RESTORED . '`');
            File::deleteDirectory($this->repository);
        }

        parent::tearDown();
    }

    public function testADamagedDatabaseComesBackIntoANewOne(): void
    {
        $database = config('database.connections.mysql_test.database');
        $this->assertIsString($database);
        $before = $this->checksums($database);

        $this->assertSame(0, Artisan::call('xgp:backup:run'));
        $this->assertSame('ok', DB::connection('central')->table('backup_runs')->value('status'));
        $this->assertContains($database, array_column(app(OffsiteBackup::class)->snapshots(), 'database'));

        DB::table('users')->delete();

        $this->assertSame(0, Artisan::call('xgp:backup:restore', ['database' => $database, '--into' => self::RESTORED]));
        $this->assertSame($before, $this->checksums(self::RESTORED));
        $this->assertSame(['First', 'Second'], array_column(DB::connection('central')->select('SELECT name FROM `' . self::RESTORED . '`.xgp_users ORDER BY id'), 'name'));

        // never over an existing database
        $this->assertSame(1, Artisan::call('xgp:backup:restore', ['database' => $database, '--into' => self::RESTORED]));
        $this->assertStringContainsString('exists already', Artisan::output());
    }

    public function testAWrongPasswordFailsTheRunAndSaysSo(): void
    {
        app(OffsiteBackup::class)->run(time());
        config(['offsite.password' => 'wrong']);

        try {
            app(OffsiteBackup::class)->run(time());
            $this->fail('The run went through with a wrong password.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('wrong password', $e->getMessage());
        }

        $last = DB::connection('central')->table('backup_runs')->orderByDesc('id')->first();
        $this->assertNotNull($last);
        $this->assertSame('failed', $last->status);
        $this->assertStringContainsString('wrong password', $last->message);
    }

    /**
     * @return array<string, string>
     */
    private function checksums(string $database): array
    {
        $sums = [];

        // the run itself is in the dump, as running
        foreach (DB::connection('central')->select("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? AND table_name <> 'xgp_backup_runs' ORDER BY table_name", [$database]) as $table) {
            $row = (array) DB::connection('central')->selectOne('CHECKSUM TABLE `' . $database . '`.`' . $table->name . '`');
            $sums[(string) $table->name] = is_scalar($row['Checksum'] ?? null) ? (string) $row['Checksum'] : '';
        }

        return $sums;
    }
}
