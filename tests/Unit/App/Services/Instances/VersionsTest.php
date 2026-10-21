<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Instances;

use App\Console\Commands\Game\RunTick;
use App\Console\Commands\Instances\ListVersions;
use App\Console\Commands\Instances\MigrateInstances;
use App\Console\Commands\Instances\SyncVersions;
use App\Console\Commands\Instances\UpgradeInstance;
use App\Http\Middleware\SelectInstance;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\Versions;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Universes play on the version of the code they were created with; every
 * version in use lies in a directory of its own under the versions path.
 * The running version copies itself there (xgp:versions:sync), a universe
 * on an older version is ticked, scanned and migrated by that version's
 * artisan and served by its index.php, and xgp:instance:upgrade moves a
 * universe forward, never back. The versions below the oldest in use are
 * pruned; the running one never.
 */
#[CoversClass(Versions::class)]
#[CoversClass(UpgradeInstance::class)]
#[CoversClass(ListVersions::class)]
#[CoversClass(SyncVersions::class)]
#[CoversClass(MigrateInstances::class)]
#[CoversClass(RunTick::class)]
#[CoversClass(SelectInstance::class)]
#[CoversClass(InstanceRegistry::class)]
class VersionsTest extends DatabaseTestCase
{
    private string $folder;

    private Versions $versions;

    private InstanceRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        config(['version.files' => '4.0.0', 'INSTALLED' => 'true', 'SECRETWORD' => 'test-secret']);
        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);

        $this->folder = sys_get_temp_dir() . '/xgp-versions-' . getmypid();
        File::deleteDirectory($this->folder);
        File::makeDirectory($this->folder . '/versions', 0o755, true);
        File::makeDirectory($this->folder . '/source/public', 0o755, true);
        File::makeDirectory($this->folder . '/source/storage/logs', 0o755, true);
        File::makeDirectory($this->folder . '/source/bootstrap/cache', 0o755, true);
        // a file before any directory: the copy must create its target first
        file_put_contents($this->folder . '/source/README.md', 'read me');
        file_put_contents($this->folder . '/source/artisan', '<?php');
        file_put_contents($this->folder . '/source/public/index.php', '<?php');
        file_put_contents($this->folder . '/source/storage/logs/laravel.log', 'never copied');
        file_put_contents($this->folder . '/source/bootstrap/cache/config.php', 'never copied');
        file_put_contents($this->folder . '/source/bootstrap/cache/routes-v7.php', 'copied');
        file_put_contents($this->folder . '/source/.env', 'APP_KEY=x');
        $this->stubVersion('3.9.0');
        $this->stubVersion('3.8.0');

        $this->registry = app(InstanceRegistry::class);
        $this->versions = new Versions($this->registry, $this->folder . '/versions', $this->folder . '/source', $this->folder . '/map.php');
        $this->app->instance(Versions::class, $this->versions);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function testANewUniverseIsPinnedToTheRunningVersion(): void
    {
        $this->register('fresh', '');
        $this->register('old', '3.9.0');

        $this->assertSame('4.0.0', $this->registry->find('fresh')?->version);
        $this->assertSame('3.9.0', $this->registry->find('old')?->version);
        $this->assertTrue($this->registry->find('fresh')?->runsOn('4.0.0'));
        $this->assertFalse($this->registry->find('old')?->runsOn('4.0.0'));
    }

    public function testUniversesRegisteredBeforeGetTheVersionThatRuns(): void
    {
        DB::connection('central')->table('instances')->insert(['slug' => 'before', 'name' => 'Before', 'database' => 'xgp_uni_before', 'mode' => 'play2win', 'status' => 'open', 'opened_at' => 1, 'version' => '']);
        \Illuminate\Support\Facades\Schema::connection('central')->table('instances', static function ($table): void {
            $table->dropColumn('version');
        });

        $migration = require base_path('database/migrations/central/2026_10_21_000015_add_version_to_instances.php');
        $migration->up();

        $this->assertSame('4.0.0', DB::connection('central')->table('instances')->where('slug', 'before')->value('version'));
    }

    public function testTheVersionsOnTheServerAndWhoPlaysOnThem(): void
    {
        $this->register('old', '3.9.0');
        $this->register('older', '3.7.0');
        $this->register('fresh', '');
        File::makeDirectory($this->folder . '/versions/not-a-version');

        $this->assertSame(['3.8.0', '3.9.0', '4.0.0'], $this->versions->available());
        $this->assertSame(['3.7.0' => ['older'], '3.9.0' => ['old'], '4.0.0' => ['fresh']], $this->versions->inUse());
        $this->assertSame('3.7.0', $this->versions->oldestInUse());
        $this->assertSame($this->folder . '/versions/3.9.0', $this->versions->directory('3.9.0'));
        $this->assertSame($this->folder . '/source', $this->versions->directory('4.0.0'));
        $this->assertNull($this->versions->directory('3.7.0'));
        $this->assertNull($this->versions->directory('../etc'));

        $this->command('xgp:versions')
            ->expectsTable(['Version', '', 'Directory', 'Universes'], [
                ['3.8.0', '', $this->folder . '/versions/3.8.0', ''],
                ['3.9.0', '', $this->folder . '/versions/3.9.0', 'old'],
                ['4.0.0', 'running', $this->folder . '/source', 'fresh'],
                ['3.7.0', 'MISSING', '', 'older'],
            ])
            ->assertSuccessful();
    }

    public function testTheRunningVersionCopiesItselfOnceAndWritesTheMap(): void
    {
        $this->register('old', '3.9.0');
        $this->register('fresh', '');
        putenv('XGP_RELEASE=abc-1');

        $this->command('xgp:versions:sync')->expectsOutputToContain('Version 4.0.0 copied to')->assertSuccessful();

        $copy = $this->folder . '/versions/4.0.0';
        $this->assertFileExists($copy . '/artisan');
        $this->assertFileExists($copy . '/README.md');
        $this->assertFileExists($copy . '/public/index.php');
        $this->assertFileExists($copy . '/bootstrap/cache/routes-v7.php');
        $this->assertFileDoesNotExist($copy . '/bootstrap/cache/config.php');
        $this->assertFileDoesNotExist($copy . '/storage/logs/laravel.log');
        $this->assertDirectoryExists($copy . '/storage/framework/views');
        $this->assertTrue(is_link($copy . '/.env'));
        $this->assertSame("abc-1\n", file_get_contents($copy . '/.release'));
        $this->assertSame(['3.8.0', '3.9.0', '4.0.0'], $this->versions->available());

        $map = require $this->folder . '/map.php';
        $this->assertSame(['current' => '4.0.0', 'root' => $this->folder . '/versions', 'universes' => ['old' => '3.9.0']], $map);

        // the same version from another build: a warning, the code there stays
        putenv('XGP_RELEASE=abc-2');
        $this->command('xgp:versions:sync')->expectsOutputToContain('WARNING')->assertSuccessful();
        $this->assertSame("abc-1\n", file_get_contents($copy . '/.release'));
        putenv('XGP_RELEASE');

        // nothing pinned any more: no map
        $this->registry->update('old', ['version' => '4.0.0']);
        $this->versions->writeMap();
        $this->assertFileDoesNotExist($this->folder . '/map.php');
    }

    public function testTheVersionsBelowTheOldestInUseArePruned(): void
    {
        $this->register('old', '3.9.0');
        $this->stubVersion('3.6.0');

        $this->command('xgp:versions', ['--prune' => true])->expectsOutputToContain('Deleted: 3.6.0, 3.8.0.')->assertSuccessful();

        $this->assertSame(['3.9.0', '4.0.0'], $this->versions->available());

        // nothing in use below the running one: everything below it goes
        $this->registry->update('old', ['version' => '4.0.0']);
        $this->assertSame(['3.9.0'], $this->versions->prune());
        $this->assertSame(['4.0.0'], $this->versions->available());
    }

    public function testAUniverseOnAnotherVersionIsTickedByThatVersion(): void
    {
        $this->register('old', '3.9.0');

        $this->command('xgp:tick')->expectsOutputToContain('old: stub 3.9.0: xgp:tick old --no-interaction')->assertSuccessful();
        $this->command('xgp:tick', ['slug' => 'old'])->expectsOutputToContain('old: stub 3.9.0: xgp:tick old')->assertSuccessful();
    }

    public function testAUniverseWhoseVersionIsMissingFailsItsTickLoudly(): void
    {
        $this->register('older', '3.7.0');

        $this->command('xgp:tick')->expectsOutputToContain('The version 3.7.0 is not on the server')->assertFailed();
    }

    public function testTheMigrationsOfAllUniversesLeaveThoseOnOtherVersionsAlone(): void
    {
        $this->register('old', '3.9.0');
        $provisioner = $this->createMock(InstanceProvisioner::class);
        $provisioner->expects($this->never())->method('migrate');
        $this->app->instance(InstanceProvisioner::class, $provisioner);

        $this->command('xgp:instance:migrate')->expectsOutputToContain('plays on version 3.9.0, not touched')->assertSuccessful();
        // named: its own version does it
        $this->command('xgp:instance:migrate', ['slug' => 'old'])->expectsOutputToContain('stub 3.9.0: xgp:instance:migrate old')->assertSuccessful();
    }

    public function testAUniverseMovesToTheRunningVersionNeverBack(): void
    {
        $this->register('old', '3.9.0');
        $provisioner = $this->createMock(InstanceProvisioner::class);
        $provisioner->expects($this->once())->method('migrate')->willReturn("Migrating: add_version\n");
        $this->app->instance(InstanceProvisioner::class, $provisioner);

        $this->command('xgp:instance:upgrade', ['slug' => 'old', '--to' => '3.8.0'])->expectsOutputToContain('3.8.0 is older')->assertFailed();
        $this->command('xgp:instance:upgrade', ['slug' => 'old', '--to' => '5.0.0'])->expectsOutputToContain('not on the server')->assertFailed();
        $this->command('xgp:instance:upgrade', ['slug' => 'nowhere'])->assertFailed();

        $this->command('xgp:instance:upgrade', ['slug' => 'old'])
            ->expectsOutputToContain('Migrating: add_version')
            ->expectsOutputToContain('now plays on version 4.0.0 (before: 3.9.0)')
            ->assertSuccessful();

        $this->assertSame('4.0.0', $this->registry->find('old')?->version);
        $this->assertFileDoesNotExist($this->folder . '/map.php');
        $this->command('xgp:instance:upgrade', ['slug' => 'old'])->expectsOutputToContain('plays on 4.0.0 already')->assertSuccessful();
    }

    public function testAUniverseMovesToAVersionInBetweenWithThatVersionsCode(): void
    {
        $this->register('older', '3.8.0');

        $this->command('xgp:instance:upgrade', ['slug' => 'older', '--to' => '3.9.0'])
            ->expectsOutputToContain('stub 3.9.0: xgp:instance:upgrade older')
            ->assertSuccessful();
    }

    public function testThisCodeNeverServesAUniverseOfAnotherVersion(): void
    {
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        $this->register('old', '3.9.0');
        // the router did not hand it over (no directory, stale map): a clear refusal
        $this->withUnencryptedCookie(SelectInstance::COOKIE, 'old')->get('/')->assertStatus(503);
        // an unknown universe goes to the lobby as before
        $this->withUnencryptedCookie(SelectInstance::COOKIE, 'nowhere')->get('/')->assertRedirect('/universes');
    }

    private function register(string $slug, string $version): void
    {
        $this->registry->register(new Instance($slug, ucfirst($slug), 'xgp_uni_' . $slug, Instance::PLAY2WIN, Instance::OPEN, time(), null, null, '', $version));
    }

    /**
     * A version's directory with an artisan that says what it was asked.
     */
    private function stubVersion(string $version): void
    {
        File::makeDirectory($this->folder . '/versions/' . $version . '/public', 0o755, true);
        file_put_contents($this->folder . '/versions/' . $version . '/artisan', '<?php fwrite(STDOUT, "stub ' . $version . ': " . implode(" ", array_slice($argv, 1)));');
        file_put_contents($this->folder . '/versions/' . $version . '/public/index.php', '<?php');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
