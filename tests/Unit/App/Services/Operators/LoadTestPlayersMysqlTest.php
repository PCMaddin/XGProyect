<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Operators;

use App\Console\Commands\Operators\ClearLoadTest;
use App\Console\Commands\Operators\SeedLoadTest;
use App\Models\User;
use App\Services\Operators\LoadTestPlayers;
use App\Services\Operators\LoadTestUniverse;
use App\Services\Operators\LoadTestWorld;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Players for a load test: registered, built up, with messages, alliances,
 * friends, fleets and ranks; only in a universe without real players, which
 * then takes sign-ups by invitation only; deleted again without a trace.
 */
#[CoversClass(LoadTestPlayers::class)]
#[CoversClass(LoadTestWorld::class)]
#[CoversClass(LoadTestUniverse::class)]
#[CoversClass(SeedLoadTest::class)]
#[CoversClass(ClearLoadTest::class)]
class LoadTestPlayersMysqlTest extends MysqlTestCase
{
    private const PASSWORD = 'load-test-password';

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);
    }

    public function testThePlayersHaveSomethingToShow(): void
    {
        $this->command('xgp:loadtest:seed', ['--players' => 12, '--password' => self::PASSWORD])->assertSuccessful();

        $players = User::where('email', 'like', '%@' . LoadTestPlayers::DOMAIN)->orderBy('id')->get();
        $this->assertCount(12, $players);
        $this->assertSame('load1@' . LoadTestPlayers::DOMAIN, $players->firstOrFail()->email);
        $last = $players->last();
        $this->assertInstanceOf(User::class, $last);
        $this->assertTrue(Hash::check(self::PASSWORD, $last->password));
        $this->assertNotNull($players->firstOrFail()->email_verified_at);
        $this->assertSame(12 * 20, DB::table('messages')->count());
        $this->assertSame(1, DB::table('alliance')->count());
        $this->assertSame(4, DB::table('users')->where('ally_id', '>', 0)->count());
        $this->assertSame(6, DB::table('buddys')->where('buddy_status', 1)->count());
        $this->assertSame(3, DB::table('fleets')->count());
        $this->assertEquals(28, DB::table('buildings')->max('building_metal_mine'));
        $this->assertEquals(12, DB::table('users_statistics')->max('user_statistic_total_rank'));
        $this->assertSame('1', app(SettingsService::class)->getString('reg_invite_only'));

        // more later: the numbers go on
        $this->command('xgp:loadtest:seed', ['--players' => 15, '--password' => self::PASSWORD])->assertSuccessful();
        $this->assertSame(15, app(LoadTestPlayers::class)->count());
        $this->assertSame(1, DB::table('users')->where('email', 'load15@' . LoadTestPlayers::DOMAIN)->count());
    }

    public function testNotInAUniverseWithRealPlayersNorWithAWeakPassword(): void
    {
        $this->command('xgp:loadtest:seed', ['--players' => 3, '--password' => 'short'])->assertFailed();

        $this->assertInstanceOf(User::class, app(RegistrationService::class)->register(['name' => 'Real', 'email' => 'real@example.com', 'password' => 'secret-password']));
        $this->command('xgp:loadtest:seed', ['--players' => 3, '--password' => self::PASSWORD])
            ->expectsOutputToContain('1 real players')
            ->assertFailed();

        $this->assertSame(0, app(LoadTestPlayers::class)->count());
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function command(string $name, array $parameters = []): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    public function testClearLeavesNothingBehind(): void
    {
        $this->command('xgp:loadtest:seed', ['--players' => 9, '--password' => self::PASSWORD])->assertSuccessful();

        $this->command('xgp:loadtest:clear')->expectsOutputToContain('9 test players deleted')->assertSuccessful();

        foreach (['users', 'planets', 'messages', 'alliance', 'alliance_statistics', 'buddys', 'fleets', 'research', 'users_statistics'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
