<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Instances;

use App\Http\Middleware\SelectInstance;
use App\Mail\Recover;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\SeasonResults;
use App\Services\RegistrationService;
use App\Models\User;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Universes in databases of their own: created, changed and migrated by
 * command, chosen in the lobby; a request plays in the chosen universe's
 * database only.
 */
#[CoversClass(InstanceProvisioner::class)]
#[CoversClass(InstanceContext::class)]
#[CoversClass(InstanceRegistry::class)]
#[CoversClass(SelectInstance::class)]
class InstancesMysqlTest extends MysqlTestCase
{
    private const DATABASES = ['xgp_test_u1', 'xgp_test_u2', 'xgp_uni_zz_test_2030_05', 'xgp_uni_zz_test_2030_06'];

    /** skipped without MariaDB: nothing to clean up */
    private bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'INSTALLED' => 'true',
            'SECRETWORD' => 'test-secret',
            'version.files' => '4.0.0',
            'database.connections.central' => config('database.connections.mysql_test'),
        ]);
        DB::purge('central');
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        $this->dropDatabases();
        $this->prepared = true;
    }

    protected function tearDown(): void
    {
        if ($this->prepared) {
            app(InstanceContext::class)->reset();
            $this->dropDatabases();
        }

        parent::tearDown();
    }

    public function testWithoutUniversesTheGameIsASingleUniverse(): void
    {
        $this->get('/')->assertOk();
        $this->get('/universes')->assertRedirect(route('home.index'));
        $this->command('xgp:instance:list')->expectsOutputToContain('single universe')->assertSuccessful();
    }

    public function testAUniverseGetsADatabaseWithTheGameAndAnAdministrator(): void
    {
        DB::table('options')->where('name', 'lang')->update(['value' => 'de']);
        $this->createUniverse('u1', 'xgp_test_u1', Instance::PAY2WIN)->assertSuccessful();

        $universe = app(InstanceRegistry::class)->find('u1');
        $this->assertNotNull($universe);
        $this->assertSame('xgp_test_u1', $universe->database);
        $this->assertSame(Instance::PAY2WIN, $universe->mode);

        // the base database holds no player, the universe its administrator
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(['Admin1', '3'], $this->inUniverse('xgp_test_u1', 'SELECT name, authlevel FROM xgp_users'));
        $this->assertSame(['pay2win'], $this->inUniverse('xgp_test_u1', "SELECT value FROM xgp_options WHERE name = 'premium_mode'"));
        $this->assertSame(['stripe,wax'], $this->inUniverse('xgp_test_u1', "SELECT value FROM xgp_options WHERE name = 'payment_methods'"));
        $this->assertSame(['Universe One'], $this->inUniverse('xgp_test_u1', "SELECT value FROM xgp_options WHERE name = 'game_name'"));
        $this->assertSame(['5000'], $this->inUniverse('xgp_test_u1', "SELECT value FROM xgp_options WHERE name = 'game_speed'"));
        // the language of the base game, unless one is given
        $this->assertSame(['de'], $this->inUniverse('xgp_test_u1', "SELECT value FROM xgp_options WHERE name = 'lang'"));
        $this->assertSame(['1'], $this->inUniverse('xgp_test_u1', 'SELECT COUNT(*) FROM xgp_planets WHERE planet_galaxy = 1 AND planet_system = 1 AND planet_planet = 1'));

        // the update migrations count as run: nothing is left to migrate
        $this->command('xgp:instance:migrate', ['slug' => 'u1'])->expectsOutputToContain('Nothing to migrate')->assertSuccessful();
    }

    public function testAUniverseIsNotCreatedTwiceNorOverAGame(): void
    {
        $this->createUniverse('u1', 'xgp_test_u1')->assertSuccessful();

        $this->createUniverse('u1', 'xgp_test_u2')->expectsOutputToContain('exists already')->assertFailed();
        $this->createUniverse('u2', 'xgp_test_u1')->expectsOutputToContain('belongs to the universe u1')->assertFailed();
        $this->createUniverse('Bad Slug', 'xgp_test_u2')->expectsOutputToContain('slug')->assertFailed();

        // a database with a game that is not registered stays untouched
        DB::connection('central')->statement('CREATE DATABASE xgp_test_u2');
        DB::connection('central')->statement('CREATE TABLE xgp_test_u2.xgp_migrations (id INT)');
        $this->createUniverse('u2', 'xgp_test_u2')->expectsOutputToContain('holds a game already')->assertFailed();
        $this->assertNull(app(InstanceRegistry::class)->find('u2'));
    }

    public function testTheModeIsChangedInTheRegistryAndTheUniverse(): void
    {
        $this->createUniverse('u1', 'xgp_test_u1', Instance::PAY2WIN)->assertSuccessful();

        $this->command('xgp:instance:update', ['slug' => 'u1', '--mode' => 'play2win', '--name' => 'Renamed'])->assertSuccessful();

        $universe = app(InstanceRegistry::class)->find('u1');
        $this->assertNotNull($universe);
        $this->assertSame(Instance::PLAY2WIN, $universe->mode);
        $this->assertSame('Renamed', $universe->name);
        $this->assertSame(['play2win'], $this->inUniverse('xgp_test_u1', "SELECT value FROM xgp_options WHERE name = 'premium_mode'"));
        $this->command('xgp:instance:update', ['slug' => 'u1', '--mode' => 'free'])->assertFailed();

        $this->command('xgp:instance:update', ['slug' => 'u1', '--payments' => 'none'])->assertSuccessful();
        $this->assertSame([''], $this->inUniverse('xgp_test_u1', "SELECT value FROM xgp_options WHERE name = 'payment_methods'"));
        $this->command('xgp:instance:update', ['slug' => 'u1', '--payments' => 'wax'])->assertSuccessful();
        $this->assertSame(['wax'], $this->inUniverse('xgp_test_u1', "SELECT value FROM xgp_options WHERE name = 'payment_methods'"));
        $this->command('xgp:instance:update', ['slug' => 'u1', '--payments' => 'paypal'])->expectsOutputToContain('ways to pay')->assertFailed();
        $this->assertSame(['wax'], $this->inUniverse('xgp_test_u1', "SELECT value FROM xgp_options WHERE name = 'payment_methods'"));
    }

    public function testTheLobbySendsPlayersToTheChosenUniverse(): void
    {
        $this->createUniverse('u1', 'xgp_test_u1')->assertSuccessful();
        $this->createUniverse('u2', 'xgp_test_u2', Instance::PAY2WIN)->assertSuccessful();
        // newest first: u2 opened a day after u1
        app(InstanceRegistry::class)->update('u1', ['opened_at' => time() - 86400]);
        config(['premium.wax.account' => 'xgpshop', 'premium.stripe.secret' => '', 'premium.stripe.webhook_secret' => '']);

        // without a choice every page leads to the lobby
        $this->get('/')->assertRedirect(route('lobby.index'));
        // how dark matter is paid: only in Pay2Win, only the ways set up on the server
        $this->get('/universes')->assertOk()->assertSee('Universe One')->assertSee('Players: 1')->assertSeeInOrder(['Universe Two', 'Payment: WAX', 'Universe One'])->assertDontSee('card');

        $backupName = config('backup.backup.name');
        $this->assertIsString($backupName);
        $choice = $this->post('/universes/u1');
        $choice->assertRedirect(route('home.index'))->assertPlainCookie(SelectInstance::COOKIE, 'u1');

        $this->withUnencryptedCookie(SelectInstance::COOKIE, 'u1')->get('/')->assertOk()->assertSee('Universe One');
        $this->assertSame('u1', app(InstanceContext::class)->current()?->slug);
        $this->assertSame('xgp_test_u1', DB::connection()->getDatabaseName());
        $this->assertSame(1, DB::table('users')->count());
        // cache and backups of their own
        $cachePrefix = config('cache.prefix');
        $this->assertIsString($cachePrefix);
        $this->assertStringEndsWith('_u1', $cachePrefix);
        $this->assertSame($backupName . '_u1', config('backup.backup.name'));
        $this->assertSame(storage_path('logs/xgproyect-u1.log'), config('logging.channels.single.path'));

        // an unknown or closed universe is not played
        $this->withUnencryptedCookie(SelectInstance::COOKIE, 'nope')->get('/')->assertRedirect(route('lobby.index'));
        app(InstanceContext::class)->reset();
        $this->assertSame($backupName, config('backup.backup.name'));
        $this->assertSame(storage_path('logs/xgproyect.log'), config('logging.channels.single.path'));
        $this->command('xgp:instance:update', ['slug' => 'u1', '--status' => 'closed'])->assertSuccessful();
        $this->post('/universes/u1')->assertRedirect(route('lobby.index'));
    }

    public function testAMailLinkNamesTheUniverse(): void
    {
        $this->createUniverse('u1', 'xgp_test_u1')->assertSuccessful();
        Mail::fake();

        // a browser that never chose in the lobby: the link chooses, and is remembered
        $this->get('/?universe=u1')->assertOk()->assertPlainCookie(SelectInstance::COOKIE, 'u1');
        $this->assertSame('u1', app(InstanceContext::class)->current()?->slug);

        $this->withUnencryptedCookie(SelectInstance::COOKIE, 'u1')->post('/account/recover', ['email' => 'admin@example.com'])->assertRedirect();
        Mail::assertSent(Recover::class, static fn (Recover $mail): bool => str_contains($mail->resetUrl, '/reset/') && str_ends_with($mail->resetUrl, '&universe=u1'));

        app(InstanceContext::class)->reset();
        $this->get('/?universe=nope')->assertRedirect(route('lobby.index'));
    }

    public function testASeasonEndsAndTheUniverseCloses(): void
    {
        $this->createUniverse('u1', 'xgp_test_u1')->assertSuccessful();
        $this->command('xgp:instance:update', ['slug' => 'u1', '--ends' => '+1 day'])->assertSuccessful();

        $universe = app(InstanceRegistry::class)->find('u1');
        $this->assertNotNull($universe);
        $this->assertEqualsWithDelta(time() + 86400, $universe->endsAt, 5);
        $this->get('/universes')->assertOk()->assertSee('Ends on ' . date('d.m.Y', time() + 86400));
        $this->withUnencryptedCookie(SelectInstance::COOKIE, 'u1')->get('/')->assertOk()->assertSee('Ends on');
        app(InstanceContext::class)->reset();

        // the end must follow the start
        $this->command('xgp:instance:update', ['slug' => 'u1', '--ends' => '-1 year'])->expectsOutputToContain('must end after')->assertFailed();

        // once the season is over nobody gets in, and the scheduler closes it
        app(InstanceRegistry::class)->update('u1', ['opened_at' => time() - 7200, 'ends_at' => time() - 60]);
        $this->withUnencryptedCookie(SelectInstance::COOKIE, 'u1')->get('/')->assertRedirect(route('lobby.index'));
        $this->command('xgp:instance:close-ended')->expectsOutput('Closed u1.')->assertSuccessful();
        $this->assertSame(Instance::CLOSED, app(InstanceRegistry::class)->find('u1')?->status);
        $this->command('xgp:instance:close-ended')->doesntExpectOutput('Closed u1.')->assertSuccessful();

        $this->command('xgp:instance:update', ['slug' => 'u1', '--ends' => 'none', '--status' => 'open'])->assertSuccessful();
        $this->assertNull(app(InstanceRegistry::class)->find('u1')->endsAt);
    }

    public function testTheRankingsAreKeptWhenTheSeasonEnds(): void
    {
        $this->command('xgp:instance:create', [
            'slug' => 'u1', '--name' => 'Universe One', '--database' => 'xgp_test_u1', '--bots' => 2,
            '--admin-name' => 'Admin1', '--admin-email' => 'admin@example.com', '--admin-password' => 'secret-password',
        ])->assertSuccessful();
        $universe = app(InstanceRegistry::class)->find('u1');
        $this->assertNotNull($universe);

        // two players: Beta has built more than Alpha; bots and the admin do not count
        app(InstanceContext::class)->runIn($universe, function (): void {
            foreach (['Alpha' => 3, 'Beta' => 12] as $name => $mine) {
                $player = app(RegistrationService::class)->register(['name' => $name, 'email' => strtolower($name) . '@example.com', 'password' => 'secret-password']);
                $this->assertInstanceOf(User::class, $player);
                $planet = DB::table('planets')->where('planet_user_id', $player->id)->value('planet_id');
                DB::table('buildings')->where('building_planet_id', $planet)->update(['building_metal_mine' => $mine, 'building_crystal_mine' => $mine]);
            }
        });

        // the configured prizes, then this universe's own
        $this->assertSame(100_000, app(InstanceRegistry::class)->rewardPlan('u1')?->pools['total']);
        $this->command('xgp:instance:update', ['slug' => 'u1', '--rewards' => 'total=1000,buildings=500', '--shares' => '60,40'])->assertSuccessful();
        $this->command('xgp:instance:update', ['slug' => 'u1', '--shares' => '70,40'])->assertFailed();
        $this->get('/universes')->assertOk()->assertSee('Season prizes: 1.500 dark matter for the top ten');

        $this->command('xgp:instance:update', ['slug' => 'u1', '--status' => 'closed'])->assertSuccessful();

        $total = app(SeasonResults::class)->forUniverse('u1')['total'] ?? [];
        $this->assertSame([600, 400], array_column($total, 'reward'));
        $this->assertSame([300, 200], array_column(app(SeasonResults::class)->forUniverse('u1')['buildings'] ?? [], 'reward'));
        // a ranking without a prize pays nothing
        $this->assertSame([], array_filter(array_column(app(SeasonResults::class)->forUniverse('u1')['ships'] ?? [], 'reward')));
        $this->assertSame(['Beta', 'Alpha'], array_column($total, 'name'));
        $this->assertSame([1, 2], array_column($total, 'rank'));
        $this->assertGreaterThan($total[1]['points'], $total[0]['points']);
        $this->assertSame(['Beta', 'Alpha'], array_column(app(SeasonResults::class)->forUniverse('u1')['buildings'] ?? [], 'name'));

        $this->get('/universes')->assertOk()->assertSee('Hall of fame')->assertSeeInOrder(['Total', 'Beta', '+600 DM', 'Alpha', '+400 DM']);
    }

    public function testTheInstalledGameIsAdopted(): void
    {
        $this->command('xgp:instance:adopt', ['slug' => 'classic', '--mode' => 'pay2win'])->assertSuccessful();

        $universe = app(InstanceRegistry::class)->find('classic');
        $this->assertSame('xgp_test', $universe?->database);
        $this->assertSame('XG Proyect', $universe->name);
        $this->assertSame('pay2win', DB::table('options')->where('name', 'premium_mode')->value('value'));
    }

    public function testEveryMonthANewUniverseOpensAndTheOldOnesClose(): void
    {
        config(['instances.monthly' => [
            'enabled' => true,
            'slug' => 'zz-test-:year-:month',
            'name' => 'Universe :number',
            'mode' => 'pay2win',
            'options' => 'game_speed=5000, fleet_speed=7000',
            'keep_open' => 1,
            'bots' => 3,
            'duration' => '1 month',
            'admin' => ['name' => 'Monthly', 'email' => 'monthly@example.com', 'password' => 'secret-password'],
        ]]);

        $this->command('xgp:instance:monthly', ['--date' => '2030-05-01'])->assertSuccessful();
        $this->command('xgp:instance:monthly', ['--date' => '2030-05-20'])->expectsOutputToContain('exists already')->assertSuccessful();
        $this->command('xgp:instance:monthly', ['--date' => '2030-06-01'])->expectsOutputToContain('Closed zz-test-2030-05')->assertSuccessful();

        $registry = app(InstanceRegistry::class);
        $this->assertSame('Universe 1', $registry->find('zz-test-2030-05')?->name);
        // the season runs until the next one opens
        $this->assertSame(strtotime('+1 month', (int) strtotime('2030-05-01')), $registry->find('zz-test-2030-05')->endsAt);
        $this->assertSame(Instance::CLOSED, $registry->find('zz-test-2030-05')->status);
        $this->assertSame('Universe 2', $registry->find('zz-test-2030-06')?->name);
        $this->assertSame(Instance::OPEN, $registry->find('zz-test-2030-06')->status);
        $this->assertSame(['7000'], $this->inUniverse('xgp_uni_zz_test_2030_06', "SELECT value FROM xgp_options WHERE name = 'fleet_speed'"));
        $this->assertSame(['Monthly'], $this->inUniverse('xgp_uni_zz_test_2030_06', 'SELECT name FROM xgp_users WHERE user_is_bot = 0'));
        // with AI opponents, each on a home planet
        $this->assertSame(['3', '3'], $this->inUniverse('xgp_uni_zz_test_2030_06', 'SELECT COUNT(*), (SELECT COUNT(*) FROM xgp_planets p JOIN xgp_users u ON u.id = p.planet_user_id WHERE u.user_is_bot = 1) FROM xgp_users WHERE user_is_bot = 1'));
    }

    private function createUniverse(string $slug, string $database, string $mode = Instance::PLAY2WIN): PendingCommand
    {
        return $this->command('xgp:instance:create', [
            'slug' => $slug,
            '--name' => $slug === 'u1' ? 'Universe One' : 'Universe Two',
            '--database' => $database,
            '--mode' => $mode,
            '--admin-name' => 'Admin1',
            '--admin-email' => 'admin@example.com',
            '--admin-password' => 'secret-password',
            '--option' => ['game_speed=5000'],
            '--payments' => 'wax,stripe',
        ]);
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

    /**
     * The first row of a query in another database, as strings.
     *
     * @return list<string>
     */
    private function inUniverse(string $database, string $sql): array
    {
        $row = DB::connection('central')->selectOne(str_replace('xgp_', $database . '.xgp_', $sql));

        return array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', array_values((array) $row));
    }

    private function dropDatabases(): void
    {
        foreach (self::DATABASES as $database) {
            DB::connection('central')->statement('DROP DATABASE IF EXISTS `' . $database . '`');
        }
    }
}
