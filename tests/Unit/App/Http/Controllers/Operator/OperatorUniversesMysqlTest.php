<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Operator;

use App\Http\Controllers\Operator\UniversesController;
use App\Models\Operator;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\SeasonPayouts;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * In the browser an operator does what xgp:instance:* does: a universe in a
 * database of its own, its mode, ways to pay, season end and prizes,
 * closing it, and paying its prizes in the own token. Each change is logged.
 */
#[CoversClass(UniversesController::class)]
#[CoversClass(SeasonPayouts::class)]
class OperatorUniversesMysqlTest extends MysqlTestCase
{
    private const DATABASE = 'xgp_uni_op_test';
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

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
            'operators.web' => true,
            'operators.allowed_ips' => '',
        ]);
        DB::purge('central');
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        DB::connection('central')->statement('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        $this->prepared = true;

        $operator = new Operator(['email' => 'ops@example.com', 'password' => 'correct horse battery', 'created_at' => time()]);
        app(TwoFactor::class)->enable($operator, self::SECRET, $this->code(-60), time() - 60);
        $this->post('/operator/login', ['email' => 'ops@example.com', 'password' => 'correct horse battery']);
        $this->post('/operator/code', ['code' => $this->code()])->assertRedirect(route('operator.universes'));
    }

    protected function tearDown(): void
    {
        if ($this->prepared) {
            app(InstanceContext::class)->reset();
            DB::connection('central')->statement('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        }

        parent::tearDown();
    }

    public function testAUniverseIsCreatedChangedAndClosedInTheBrowser(): void
    {
        $this->get('/operator/universes')->assertOk()->assertSee('No universe yet.');
        $this->get('/operator/universes/create')->assertOk()->assertSee('value="ops@example.com"', false);

        $this->post('/operator/universes', $this->universe(['slug' => 'Bad Slug']))->assertSessionHasErrors('slug');
        $this->post('/operator/universes', $this->universe(['ends' => '2001-01-01T00:00']))->assertSessionHasErrors('ends');
        $this->post('/operator/universes', $this->universe())->assertRedirect(route('operator.universes.edit', 'op-test'));

        $universe = app(InstanceRegistry::class)->find('op-test');
        $this->assertNotNull($universe);
        $this->assertSame([self::DATABASE, Instance::PAY2WIN], [$universe->database, $universe->mode]);
        $this->assertSame(['wax'], $this->inUniverse("SELECT value FROM xgp_options WHERE name = 'payment_methods'"));
        $this->assertSame(['Mayor'], $this->inUniverse('SELECT name FROM xgp_users WHERE authlevel = 3'));
        // the same slug again: refused with the reason
        $this->post('/operator/universes', $this->universe())->assertSessionHasErrors(['slug' => 'The universe op-test exists already.']);

        $this->get('/operator/universes/op-test')->assertOk()->assertSee(self::DATABASE)->assertSee('value="wax" checked', false);
        $this->post('/operator/universes/op-test', [
            'name' => 'Renamed', 'mode' => 'play2win', 'status' => 'open', 'payments' => ['stripe', 'token'],
            'ends' => date('Y-m-d\TH:i', time() + 7 * 86400), 'rewards' => 'total=5000', 'shares' => '60,40',
        ])->assertRedirect(route('operator.universes.edit', 'op-test'))->assertSessionHas('status');

        $universe = app(InstanceRegistry::class)->find('op-test');
        $this->assertNotNull($universe);
        $this->assertSame(['Renamed', Instance::PLAY2WIN], [$universe->name, $universe->mode]);
        $this->assertSame(['stripe,token'], $this->inUniverse("SELECT value FROM xgp_options WHERE name = 'payment_methods'"));
        $this->assertSame(['play2win'], $this->inUniverse("SELECT value FROM xgp_options WHERE name = 'premium_mode'"));
        $this->assertSame(['total' => 5000], app(InstanceRegistry::class)->rewardPlan('op-test')?->pools);

        $this->post('/operator/universes/op-test', ['name' => 'Renamed', 'mode' => 'play2win', 'status' => 'open', 'rewards' => 'total=5000', 'shares' => '90,90'])
            ->assertSessionHasErrors('name');

        $this->post('/operator/universes/op-test/close')->assertRedirect(route('operator.universes.season', 'op-test'));
        $this->assertSame(Instance::CLOSED, app(InstanceRegistry::class)->find('op-test')?->status);
        $this->get('/operator/universes/op-test/season')->assertOk()->assertSee('No prizes in this season.');

        $this->get('/operator/universes')->assertOk()->assertSee('Renamed')
            ->assertSee('universe created')->assertSee('universe changed')->assertSee('universe closed');
        $this->assertSame(
            ['login', 'universe_created', 'universe_changed', 'universe_closed'],
            DB::connection('central')->table('operator_events')->orderBy('id')->pluck('action')->all()
        );
        $this->get('/operator/universes/nowhere')->assertNotFound();
    }

    public function testPrizesArePaidInTheTokenFromTheBrowser(): void
    {
        config(['premium.wax.account' => 'xgpshop', 'premium.token' => ['contract' => 'xgptokens', 'symbol' => 'XGP', 'decimals' => 4, 'dark_matter_per_token' => 100]]);
        $central = DB::connection('central');
        $central->table('instances')->insert(['slug' => 'old', 'name' => 'Old', 'database' => 'xgp_old', 'mode' => 'pay2win', 'status' => 'closed', 'opened_at' => 1]);
        $central->table('season_results')->insert([
            ['universe' => 'old', 'category' => 'total', 'rank' => 1, 'user_id' => 5, 'name' => 'Winner', 'points' => 9.0, 'frozen_at' => 1, 'reward' => 30_000, 'wax_account' => 'winner.wam', 'claimed_at' => null, 'claimed_universe' => null],
            ['universe' => 'old', 'category' => 'total', 'rank' => 2, 'user_id' => 6, 'name' => 'Second', 'points' => 8.0, 'frozen_at' => 1, 'reward' => 20_000, 'wax_account' => null, 'claimed_at' => null, 'claimed_universe' => null],
            ['universe' => 'old', 'category' => 'military', 'rank' => 1, 'user_id' => 7, 'name' => 'Fighter', 'points' => 7.0, 'frozen_at' => 1, 'reward' => 4_000, 'wax_account' => 'fighter.wam', 'claimed_at' => 5, 'claimed_universe' => 'next'],
        ]);

        $this->get('/operator/universes/old/season')->assertOk()->assertSee('Winner')->assertSee('redeemed as dark matter in')->assertSee('Prepare payout')
            ->assertDontSee('id="payout-actions"', false);

        $this->post('/operator/universes/old/payout')->assertRedirect(route('operator.universes.season', 'old'));
        $this->get('/operator/universes/old/season')->assertOk()->assertSee('id="payout-actions"', false)
            ->assertSee('300.0000 XGP')->assertSee('xgp-prize:1')->assertSee('reserved for payout')->assertDontSee('fighter.wam&quot;', false);

        $this->post('/operator/universes/old/release')->assertSessionHas('status', '1 prizes can be redeemed as dark matter again.');
        $this->assertNull($central->table('season_results')->where('id', 1)->value('claimed_universe'));
        $this->assertSame(['payout_reserved', 'payout_released'], $central->table('operator_events')->whereIn('action', ['payout_reserved', 'payout_released'])->orderBy('id')->pluck('action')->all());

        // a running season pays nothing
        $central->table('instances')->insert(['slug' => 'live', 'name' => 'Live', 'database' => 'xgp_live', 'mode' => 'pay2win', 'status' => 'open', 'opened_at' => 1]);
        $this->post('/operator/universes/live/payout')->assertSessionHasErrors('payout');
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function universe(array $changes = []): array
    {
        return $changes + [
            'slug' => 'op-test', 'name' => 'Operator Test', 'mode' => 'pay2win', 'payments' => ['wax'],
            'ends' => date('Y-m-d\TH:i', time() + 30 * 86400), 'bots' => 0,
            'admin_name' => 'Mayor', 'admin_email' => 'mayor@example.com', 'admin_password' => 'secret-password',
        ];
    }

    private function code(int $offset = 0): string
    {
        $totp = new Totp();

        return $totp->code(self::SECRET, $totp->step(time() + $offset));
    }

    /**
     * The first row of a query in the universe's database, as strings.
     *
     * @return list<string>
     */
    private function inUniverse(string $sql): array
    {
        $row = DB::connection('central')->selectOne(str_replace('xgp_', self::DATABASE . '.xgp_', $sql));

        return array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', array_values((array) $row));
    }
}
