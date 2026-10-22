<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Instances;

use App\Console\Commands\Instances\ArchiveInstance;
use App\Console\Commands\Instances\UnarchiveInstance;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\UniverseArchive;
use App\Services\Operators\OffsiteBackup;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Process\ExecutableFinder;
use Tests\MysqlTestCase;

/**
 * A closed universe archived for good and brought back: the database goes
 * to the backup server (or a file), is checked, then dropped; it comes back
 * with every table as it was. An open universe is never archived, and a
 * dump that misses tables leaves the database where it is. Needs restic and
 * mariadb-dump.
 */
#[CoversClass(UniverseArchive::class)]
#[CoversClass(ArchiveInstance::class)]
#[CoversClass(UnarchiveInstance::class)]
class UniverseArchiveMysqlTest extends MysqlTestCase
{
    private const UNIVERSE = 'xgp_test_uni_archive';

    private string $repository;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $finder = new ExecutableFinder();

        if ($finder->find('restic') === null || $finder->find('mariadb-dump') === null) {
            $this->markTestSkipped('restic and mariadb-dump are needed.');
        }

        $this->repository = sys_get_temp_dir() . '/xgp-archive-restic-' . getmypid();
        $this->folder = sys_get_temp_dir() . '/xgp-archive-files-' . getmypid();
        File::deleteDirectory($this->repository);
        File::deleteDirectory($this->folder);
        File::makeDirectory($this->folder);
        config([
            'database.connections.central' => config('database.connections.mysql_test'),
            'offsite.repository' => $this->repository,
            'offsite.password' => 'test-password',
        ]);
        DB::purge('central');
        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);

        // a universe of its own: two tables with rows
        $base = DB::connection('central');
        $base->statement('DROP DATABASE IF EXISTS `' . self::UNIVERSE . '`');
        $base->statement('CREATE DATABASE `' . self::UNIVERSE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $base->statement('CREATE TABLE `' . self::UNIVERSE . '`.`xgp_users` (id INT PRIMARY KEY, name VARCHAR(64))');
        $base->statement('CREATE TABLE `' . self::UNIVERSE . '`.`xgp_planets` (id INT PRIMARY KEY, owner INT, name VARCHAR(64))');
        $base->insert('INSERT INTO `' . self::UNIVERSE . "`.`xgp_users` VALUES (1, 'Winner'), (2, 'Second')");
        $base->insert('INSERT INTO `' . self::UNIVERSE . "`.`xgp_planets` VALUES (1, 1, 'Home'), (2, 2, 'Colony')");
        $base->table('instances')->insert([
            'slug' => 'old', 'name' => 'Old', 'database' => self::UNIVERSE, 'mode' => Instance::PLAY2WIN,
            'status' => Instance::CLOSED, 'opened_at' => time() - 90 * 86400, 'closed_at' => time() - 40 * 86400,
        ]);
        app(InstanceRegistry::class)->refresh();

        // the universe's update migrations are not what this is about
        $provisioner = $this->createMock(InstanceProvisioner::class);
        $provisioner->method('migrate')->willReturn('');
        $this->app->instance(InstanceProvisioner::class, $provisioner);
    }

    protected function tearDown(): void
    {
        if (isset($this->repository)) {
            DB::connection('central')->statement('DROP DATABASE IF EXISTS `' . self::UNIVERSE . '`');
            File::deleteDirectory($this->repository);
            File::deleteDirectory($this->folder);
        }

        parent::tearDown();
    }

    public function testArchivedToTheBackupServerAndBack(): void
    {
        $before = $this->checksums();

        $this->assertSame(0, Artisan::call('xgp:instance:archive', ['slug' => 'old']));

        $this->assertFalse($this->exists());
        $old = $this->universe();
        $this->assertSame(Instance::ARCHIVED, $old->status);
        $this->assertMatchesRegularExpression('/^restic:[0-9a-f]{64}$/', $old->archive);
        $this->assertNotContains(self::UNIVERSE, app(OffsiteBackup::class)->databases());
        $this->assertSame([], app(InstanceRegistry::class)->withDatabase());
        $this->assertSame('universe_archived', DB::connection('central')->table('operator_events')->value('action'));
        // the nightly keep rules look at other snapshots
        app(OffsiteBackup::class)->run(time());
        $this->assertNotContains(self::UNIVERSE, array_column(app(OffsiteBackup::class)->snapshots(), 'database'));

        $this->assertSame(0, Artisan::call('xgp:instance:unarchive', ['slug' => 'old']));

        $this->assertSame($before, $this->checksums());
        $this->assertSame(Instance::CLOSED, $this->universe()->status);
        $this->assertSame('', $this->universe()->archive);
    }

    public function testArchivedIntoAFileAndBack(): void
    {
        $before = $this->checksums();
        $file = $this->folder . '/old.sql.gz';

        $this->assertSame(0, Artisan::call('xgp:instance:archive', ['slug' => 'old', '--file' => $file]));

        $this->assertFileExists($file);
        $this->assertFalse($this->exists());
        $this->assertSame('file:' . realpath($file), $this->universe()->archive);
        // a file that exists is never written over
        DB::connection('central')->table('instances')->insert(['slug' => 'other', 'name' => 'Other', 'database' => 'xgp_test_uni_other', 'mode' => Instance::PLAY2WIN, 'status' => Instance::CLOSED, 'opened_at' => 1]);
        $this->assertSame(1, Artisan::call('xgp:instance:archive', ['slug' => 'other', '--file' => $file]));

        $this->assertSame(0, Artisan::call('xgp:instance:unarchive', ['slug' => 'old']));
        $this->assertSame($before, $this->checksums());
    }

    public function testAnOpenUniverseIsNeverArchived(): void
    {
        DB::connection('central')->table('instances')->where('slug', 'old')->update(['status' => Instance::OPEN]);

        $this->assertSame(1, Artisan::call('xgp:instance:archive', ['slug' => 'old']));

        $this->assertStringContainsString('Only a closed universe', Artisan::output());
        $this->assertTrue($this->exists());
    }

    public function testADumpThatMissesTablesLeavesTheDatabase(): void
    {
        // a "dump" without a single table
        config(['offsite.dump' => 'true']);

        $this->assertSame(1, Artisan::call('xgp:instance:archive', ['slug' => 'old', '--file' => $this->folder . '/broken.sql.gz']));

        $this->assertStringContainsString('holds 0 of 2 tables', Artisan::output());
        $this->assertTrue($this->exists());
        $this->assertSame(Instance::CLOSED, $this->universe()->status);
    }

    public function testDueAfterTheConfiguredDays(): void
    {
        DB::connection('central')->table('instances')->insert([
            ['slug' => 'recent', 'name' => 'Recent', 'database' => 'xgp_test_uni_recent', 'mode' => Instance::PLAY2WIN, 'status' => Instance::CLOSED, 'opened_at' => 1, 'closed_at' => time() - 5 * 86400],
            ['slug' => 'running', 'name' => 'Running', 'database' => 'xgp_test_uni_running', 'mode' => Instance::PLAY2WIN, 'status' => Instance::OPEN, 'opened_at' => 1, 'closed_at' => null],
        ]);
        $archive = app(UniverseArchive::class);

        config(['instances.archive_after_days' => 0]);
        $this->assertSame([], $archive->due(time()));

        config(['instances.archive_after_days' => 30]);
        $this->assertSame(['old'], array_map(static fn (Instance $instance): string => $instance->slug, $archive->due(time())));

        $this->assertSame(0, Artisan::call('xgp:instance:archive', ['--due' => true]));
        $this->assertFalse($this->exists());
        $this->assertSame([], $archive->due(time()));
    }

    public function testTheLobbyShowsAnArchivedUniverseWithoutAskingItsDatabase(): void
    {
        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        Artisan::call('xgp:instance:archive', ['slug' => 'old']);

        $this->get('/universes')->assertOk()->assertSee('Old');

        // before, every view of the lobby asked the dropped database: an error a view
        $this->assertSame(0, DB::connection('central')->table('error_groups')->count());
    }

    public function testNeverIntoAFileTheWebServerServes(): void
    {
        $file = public_path('old.sql.gz');

        $this->assertSame(1, Artisan::call('xgp:instance:archive', ['slug' => 'old', '--file' => $file]));

        $this->assertStringContainsString('Not under public/', Artisan::output());
        $this->assertFileDoesNotExist($file);
        $this->assertTrue($this->exists());
    }

    public function testAViewDoesNotCountAsATable(): void
    {
        $before = $this->checksums();
        DB::connection('central')->statement('CREATE VIEW `' . self::UNIVERSE . '`.`v_users` AS SELECT id, name FROM `' . self::UNIVERSE . '`.`xgp_users`');

        $this->assertSame(0, Artisan::call('xgp:instance:archive', ['slug' => 'old']));
        $this->assertSame(0, Artisan::call('xgp:instance:unarchive', ['slug' => 'old']));

        $this->assertSame($before, $this->checksums());
        $this->assertSame([['id' => 1, 'name' => 'Winner'], ['id' => 2, 'name' => 'Second']], array_map(static fn (object $row): array => (array) $row, DB::connection('central')->select('SELECT id, name FROM `' . self::UNIVERSE . '`.`v_users` ORDER BY id')));
    }

    public function testARestoreThatBreaksOffLeavesNoHalfDatabase(): void
    {
        $before = $this->checksums();
        Artisan::call('xgp:instance:archive', ['slug' => 'old']);

        // a client that fails at once: the database was created, then nothing loaded
        config(['offsite.client' => 'false']);
        $this->assertSame(1, Artisan::call('xgp:instance:unarchive', ['slug' => 'old']));
        $this->assertFalse($this->exists());
        $this->assertSame(Instance::ARCHIVED, $this->universe()->status);

        config(['offsite.client' => 'mariadb']);
        $this->assertSame(0, Artisan::call('xgp:instance:unarchive', ['slug' => 'old']));
        $this->assertSame($before, $this->checksums());
    }

    public function testAnArchivedUniverseCannotBeChanged(): void
    {
        Artisan::call('xgp:instance:archive', ['slug' => 'old']);
        // the real one, not the mock of setUp()
        $this->app->forgetInstance(InstanceProvisioner::class);

        $this->expectExceptionMessage('is archived');

        app(InstanceProvisioner::class)->change($this->universe(), ['status' => Instance::OPEN]);
    }

    private function universe(): Instance
    {
        $instance = app(InstanceRegistry::class)->find('old');
        $this->assertNotNull($instance);

        return $instance;
    }

    private function exists(): bool
    {
        return DB::connection('central')->select('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [self::UNIVERSE]) !== [];
    }

    /**
     * @return array<string, string>
     */
    private function checksums(): array
    {
        $sums = [];

        foreach (['xgp_users', 'xgp_planets'] as $table) {
            $row = (array) DB::connection('central')->selectOne('CHECKSUM TABLE `' . self::UNIVERSE . '`.`' . $table . '`');
            $sums[$table] = is_scalar($row['Checksum'] ?? null) ? (string) $row['Checksum'] : '';
        }

        return $sums;
    }
}
