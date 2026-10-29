<?php

declare(strict_types=1);

namespace Tests\Unit\App\Console;

use App\Console\Commands\Game\InstallGame;
use App\Services\Instances\GameInstaller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * The installer without a browser, as the container's deploy script runs it
 * on every deploy.
 */
#[CoversClass(InstallGame::class)]
#[CoversClass(GameInstaller::class)]
class InstallGameMysqlTest extends MysqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'version.files' => '4.0.0', 'database.connections.central' => config('database.connections.mysql_test')]);
        DB::purge('central');
        Artisan::call('db:wipe', ['--force' => true]);
    }

    public function testAnEmptyDatabaseGetsTheGameAndAnAdministrator(): void
    {
        $this->install()->expectsOutput('The game is installed.')->assertSuccessful();

        $admin = DB::table('users')->first(['name', 'email', 'authlevel']);
        $this->assertNotNull($admin);
        $this->assertSame(['Admin', 'admin@example.com', 3], [$admin->name, $admin->email, (int) $admin->authlevel]);
        $this->assertSame(1, DB::table('planets')->count());
        $this->assertTrue(DB::table('options')->where('name', 'game_name')->exists());
        $this->assertTrue(DB::table('languages')->exists());
        // the update migrations count as run: the next deploy does not run them again
        $this->assertTrue(DB::table('migrations')->where('migration', 'like', '%add_indexes_for_large_universes')->exists());
        // ready for universes
        $this->assertTrue(Schema::connection('central')->hasTable('instances'));
    }

    public function testAnInstalledGameIsLeftAsItIsWithoutAsking(): void
    {
        $this->install()->assertSuccessful();
        DB::table('users')->update(['name' => 'Renamed']);

        $command = $this->artisan('xgp:install');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutput('The database holds a game already; nothing changed.')->assertSuccessful();
        $this->assertSame(['Renamed'], DB::table('users')->pluck('name')->all());
    }

    public function testAFailedInstallationLeavesAnEmptyDatabaseForTheNextAttempt(): void
    {
        // the seeder of the options needs the version of the code
        config(['version.files' => null]);

        $this->install()->assertFailed();
        $this->assertSame([], Schema::getTables(DB::getDatabaseName()));

        config(['version.files' => '4.0.0']);
        $this->install()->expectsOutput('The game is installed.')->assertSuccessful();
    }

    public function testADatabaseWithOtherTablesIsLeftAlone(): void
    {
        DB::statement('CREATE TABLE other_data (id INT)');

        $this->install()->expectsOutputToContain('install into an empty database')->assertFailed();
        $this->assertSame(['other_data'], array_column(Schema::getTables(DB::getDatabaseName()), 'name'));
    }

    public function testWithoutConfigurationNothingIsInstalled(): void
    {
        config(['INSTALLED' => 'false']);

        $this->install()->assertFailed();
        $this->assertFalse(Schema::hasTable('migrations'));
    }

    private function install(): PendingCommand
    {
        $command = $this->artisan('xgp:install', ['--admin-name' => 'Admin', '--admin-email' => 'admin@example.com', '--admin-password' => 'secret-password']);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
