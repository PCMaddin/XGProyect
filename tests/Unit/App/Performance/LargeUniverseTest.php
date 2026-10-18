<?php

declare(strict_types=1);

namespace Tests\Unit\App\Performance;

use App\Exceptions\UniverseFull;
use App\Http\Controllers\Game\HighscoreController;
use App\Models\Options;
use App\Models\User;
use App\Services\Admin\PlayerChoices;
use App\Services\Game\PlanetService;
use App\Services\SessionService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\DatabaseTestCase;

/**
 * What a universe with very many players needs: indexes for the queries of
 * every page view, a job due taken by one page view only, a session check
 * without bcrypt, a bounded search for a free place, highscore pages beyond
 * rank 10 000 and admin lists that do not hold every player.
 */
#[CoversClass(SettingsService::class)]
#[CoversClass(SessionService::class)]
#[CoversClass(PlanetService::class)]
#[CoversClass(PlayerChoices::class)]
#[CoversClass(HighscoreController::class)]
class LargeUniverseTest extends DatabaseTestCase
{
    public function testThePageQueriesHaveTheirIndexes(): void
    {
        $this->assertTrue(Schema::hasIndex('planets', ['planet_user_id', 'planet_destroyed', 'planet_type']));
        $this->assertTrue(Schema::hasIndex('planets', ['planet_galaxy', 'planet_system', 'planet_planet', 'planet_type']));
        $this->assertTrue(Schema::hasIndex('messages', ['message_receiver', 'message_read']));
        $this->assertTrue(Schema::hasIndex('fleets', ['fleet_mess', 'fleet_start_time']));
        $this->assertTrue(Schema::hasIndex('fleets', ['fleet_end_time']));
        $this->assertTrue(Schema::hasIndex('users_statistics', ['user_statistic_total_rank']));
    }

    public function testOnlyOneOfThePageViewsGetsAJobDue(): void
    {
        Options::insert(['name' => 'stat_last_update', 'value' => '100', 'type' => 'int']);
        $first = new SettingsService();
        $second = new SettingsService();

        // both read 100, both find the job due
        $this->assertSame([100, 100], [$first->getInt('stat_last_update'), $second->getInt('stat_last_update')]);
        $this->assertTrue($first->claim('stat_last_update', 100, 5000));
        $this->assertFalse($second->claim('stat_last_update', 100, 5000));
        $this->assertSame(5000, $first->getInt('stat_last_update'));

        // an empty value counts as 0
        Options::insert(['name' => 'bots_last_run', 'value' => '', 'type' => 'int']);
        $this->assertTrue((new SettingsService())->claim('bots_last_run', 0, 7));
    }

    public function testTheSessionIsCheckedWithoutBcrypt(): void
    {
        config(['SECRETWORD' => 'test-secret']);
        $sessions = new SessionService();
        $hash = Hash::make('secret-password');

        $sessions->setLoginData(5, $hash);
        $stored = session('user_password');
        $this->assertIsString($stored);
        $this->assertStringStartsWith('hmac:', $stored);
        $this->assertTrue($sessions->matches($hash, $stored));
        // a new password ends the session
        $this->assertFalse($sessions->matches(Hash::make('another password'), $stored));
        $this->assertFalse($sessions->matches($hash, 'hmac:' . str_repeat('0', 64)));

        // signed in before: checked once the old way, then the fingerprint
        $legacy = Hash::make($hash . '-test-secret');
        session(['user_password' => $legacy]);
        $this->assertTrue($sessions->matches($hash, $legacy));
        $this->assertSame($stored, session('user_password'));
        $this->assertFalse($sessions->matches($hash, Hash::make('wrong-test-secret')));
    }

    public function testANewHomePlanetGoesToTheNextFreePlace(): void
    {
        $this->lastPlace(3, 17, 8);
        $this->planetAt(3, 17, 8);
        $this->planetAt(3, 17, 12);

        $this->assertSame(['galaxy' => 3, 'system' => 17, 'planet' => 10], app(PlanetService::class)->calculateNewPlanetPosition());
        $this->planetAt(3, 17, 10);

        // the system is full: the next one
        $this->assertSame(['galaxy' => 3, 'system' => 18, 'planet' => 4], app(PlanetService::class)->calculateNewPlanetPosition());
        $this->assertSame('18', DB::table('options')->where('name', 'lastsettedsystempos')->value('value'));
    }

    public function testAFullUniverseTakesNoMorePlayers(): void
    {
        $this->lastPlace(MAX_GALAXY_IN_WORLD, MAX_SYSTEM_IN_GALAXY, 4);
        $rows = [];

        for ($galaxy = 1; $galaxy <= MAX_GALAXY_IN_WORLD; $galaxy++) {
            for ($system = 1; $system <= MAX_SYSTEM_IN_GALAXY; $system++) {
                foreach ([4, 6, 8, 10, 12] as $position) {
                    $rows[] = ['planet_galaxy' => $galaxy, 'planet_system' => $system, 'planet_planet' => $position];
                }
            }

            DB::table('planets')->insert($rows);
            $rows = [];
        }

        // one place left, in the first system: found after going round once
        DB::table('planets')->where(['planet_galaxy' => 1, 'planet_system' => 1, 'planet_planet' => 6])->delete();
        $this->assertSame(['galaxy' => 1, 'system' => 1, 'planet' => 6], app(PlanetService::class)->calculateNewPlanetPosition());
        $this->planetAt(1, 1, 6);

        // the place left lies before the last one given, in its own system: found at the end of the round
        DB::table('options')->where('name', 'lastsettedgalaxypos')->update(['value' => MAX_GALAXY_IN_WORLD]);
        DB::table('options')->where('name', 'lastsettedsystempos')->update(['value' => MAX_SYSTEM_IN_GALAXY]);
        DB::table('options')->where('name', 'lastsettedplanetpos')->update(['value' => 8]);
        DB::table('planets')->where(['planet_galaxy' => MAX_GALAXY_IN_WORLD, 'planet_system' => MAX_SYSTEM_IN_GALAXY, 'planet_planet' => 4])->delete();
        $this->assertSame(['galaxy' => MAX_GALAXY_IN_WORLD, 'system' => MAX_SYSTEM_IN_GALAXY, 'planet' => 4], app(PlanetService::class)->calculateNewPlanetPosition());
        $this->planetAt(MAX_GALAXY_IN_WORLD, MAX_SYSTEM_IN_GALAXY, 4);

        // none: the registration says so, the search ends (it called itself without end)
        $this->expectException(UniverseFull::class);
        app(PlanetService::class)->calculateNewPlanetPosition();
    }

    public function testTheRegistrationSaysTheUniverseIsFull(): void
    {
        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret']);

        foreach (['lang' => 'en', 'reg_enable' => '1', 'reg_welcome_message' => '0', 'reg_welcome_email' => '0'] as $name => $value) {
            Options::insert(['name' => $name, 'value' => $value, 'type' => 'int']);
        }

        $planets = $this->createStub(PlanetService::class);
        $planets->method('calculateNewPlanetPosition')->willThrowException(new UniverseFull());
        $this->app->instance(PlanetService::class, $planets);

        $this->post('/register', ['username' => 'Latecomer', 'password' => 'Geheim12345', 'email' => 'late@example.com', 'agb' => 'on'])
            ->assertSessionHasErrors(['username' => __('home/register.re_universe_full')], null, 'register');
        $this->assertSame(0, User::count());
    }

    public function testAdminListsHoldAPageOfPlayersAndFindTheOthers(): void
    {
        $rows = [];

        for ($id = 1; $id <= PlayerChoices::LIMIT + 20; $id++) {
            $rows[] = ['id' => $id, 'name' => sprintf('pilot%04d', $id), 'email' => $id . '@example.com', 'home_planet_id' => $id, 'current_planet' => $id];
        }

        DB::table('users')->insert($rows);

        DB::enableQueryLog();
        $choices = app(PlayerChoices::class)->choose(null, PlayerChoices::LIMIT + 15);
        // the database sends a page, not every player
        $this->assertStringContainsString('limit ' . (PlayerChoices::LIMIT + 1), (string) (DB::getQueryLog()[0]['query'] ?? ''));
        // a page, and the owner of the planet even when not on it
        $this->assertTrue($choices['more']);
        $this->assertCount(PlayerChoices::LIMIT + 1, $choices['players']);
        $this->assertSame('pilot0515', $choices['players']->first()?->name);

        $this->app->instance('request', Request::create('/admin/ban', 'GET', ['find' => 'pilot051']));
        $found = app(PlayerChoices::class)->choose(static fn ($query) => $query->where('users.id', '<>', 512));
        $this->assertFalse($found['more']);
        $this->assertSame(['pilot0510', 'pilot0511', 'pilot0513', 'pilot0514', 'pilot0515', 'pilot0516', 'pilot0517', 'pilot0518', 'pilot0519'], $found['players']->pluck('name')->all());
    }

    public function testHighscorePagesGoOnAfterRank10000(): void
    {
        $controller = app(HighscoreController::class);
        $offset = new ReflectionMethod(HighscoreController::class, 'pageOffset');
        $this->assertSame([0, 100, 10000, 999900], array_map(fn (int $range): mixed => $offset->invoke($controller, $range), [1, 101, 10001, 999901]));

        // a million players: about 150 pages to choose, the current one among them
        $list = (new ReflectionMethod(HighscoreController::class, 'buildRangeList'))->invoke($controller, 1000000, 500001);
        $this->assertIsString($list);
        $this->assertLessThan(200, substr_count($list, '<option'));
        $this->assertStringContainsString('<option value="500001" SELECTED>500001-500100</option>', $list);
        $this->assertStringContainsString('<option value="999901">999901-1000000</option>', $list);
        $this->assertStringContainsString('<option value="1">1-100</option>', $list);
    }

    private function lastPlace(int $galaxy, int $system, int $planet): void
    {
        foreach (['lastsettedgalaxypos' => $galaxy, 'lastsettedsystempos' => $system, 'lastsettedplanetpos' => $planet] as $name => $value) {
            Options::insert(['name' => $name, 'value' => (string) $value, 'type' => 'int']);
        }
    }

    private function planetAt(int $galaxy, int $system, int $planet): void
    {
        DB::table('planets')->insert(['planet_galaxy' => $galaxy, 'planet_system' => $system, 'planet_planet' => $planet]);
    }
}
