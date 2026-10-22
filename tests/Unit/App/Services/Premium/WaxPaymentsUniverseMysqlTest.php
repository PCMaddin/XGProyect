<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Premium;

use App\Services\Premium\WaxChain;
use App\Console\Commands\Premium\CheckWaxPayments;
use App\Models\DarkMatterOrder;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use App\Services\Premium\WaxPayments;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * One WAX account serves every universe: the memo names the universe, and
 * the transfer pays the order in that universe's database only. The
 * transfers are kept in the base database.
 *
 * The universe's database is xgp_test_wax, created and dropped here.
 */
#[CoversClass(WaxPayments::class)]
#[CoversClass(CheckWaxPayments::class)]
class WaxPaymentsUniverseMysqlTest extends MysqlTestCase
{
    use WaxFixtures;

    private const UNIVERSE_DATABASE = 'xgp_test_wax';

    private const PLAYER = 7;

    /** @var list<array<string, mixed>> */
    private array $actions = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'premium.wax.account' => 'xgpshop',
            'premium.wax.api_url' => 'https://hyperion.example.org',
            'premium.wax.verify_url' => 'https://second.example.org',
            'database.connections.central' => config('database.connections.mysql_test'),
        ]);
        DB::purge('central');
        Artisan::call('migrate', ['--path' => 'database/migrations/central', '--force' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'hyperion.example.org/v1/chain/get_info' => Http::response(['chain_id' => WaxChain::CHAIN_IDS['mainnet'], 'last_irreversible_block_num' => 5000]),
            'hyperion.example.org/v2/history/get_actions*' => fn () => Http::response(['actions' => $this->actions]),
            // the second server knows the same transactions
            'second.example.org/v2/history/get_transaction*' => fn (\Illuminate\Http\Client\Request $request) => Http::response([
                'executed' => true, 'lib' => 5000,
                'actions' => array_values(array_filter($this->actions, static fn (array $action): bool => ($action['trx_id'] ?? null) === $request['id'])),
            ]),
        ]);

        DB::statement('DROP DATABASE IF EXISTS `' . self::UNIVERSE_DATABASE . '`');
        DB::statement('CREATE DATABASE `' . self::UNIVERSE_DATABASE . '`');

        app(InstanceRegistry::class)->register($this->universe());
        app(InstanceContext::class)->runIn($this->universe(), function (): void {
            Artisan::call('migrate', ['--path' => 'database/migrations/install', '--force' => true]);
            $this->player();
        });

        // the same player and order number in the base database
        $this->player();
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql_test') {
            app(InstanceContext::class)->reset();
            DB::statement('DROP DATABASE IF EXISTS `' . self::UNIVERSE_DATABASE . '`');
        }

        parent::tearDown();
    }

    public function testTheTransferPaysTheOrderInTheUniverseOfItsMemo(): void
    {
        $this->actions = [
            self::action(['global_sequence' => 1, 'memo' => 'xgp:wax-uni:1']),
            // without a universe, or another one: nobody's order
            self::action(['global_sequence' => 2, 'memo' => 'xgp:1']),
            self::action(['global_sequence' => 3, 'memo' => 'xgp:nowhere:1']),
        ];

        $command = $this->artisan('xgp:wax:check');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutput('credited: 1')->expectsOutput('unknown: 2')->assertSuccessful()->run();

        $this->assertSame([10_000, DarkMatterOrder::PAID], app(InstanceContext::class)->runIn($this->universe(), fn (): array => $this->state()));
        $this->assertSame([0, DarkMatterOrder::PENDING], $this->state());
        $this->assertNull(app(InstanceContext::class)->current());

        $rows = DB::table('wax_transfers')->orderBy('sequence')->get(['universe', 'result'])->map(static fn (object $row): array => (array) $row)->all();
        $this->assertSame([
            ['universe' => 'wax-uni', 'result' => 'credited'],
            ['universe' => null, 'result' => 'unknown'],
            ['universe' => null, 'result' => 'unknown'],
        ], $rows);
    }

    private function universe(): Instance
    {
        return new Instance('wax-uni', 'WAX', self::UNIVERSE_DATABASE, Instance::PAY2WIN, Instance::OPEN, time());
    }

    private function player(): void
    {
        DB::table('premium')->insert(['premium_user_id' => self::PLAYER, 'premium_dark_matter' => 0]);
        DB::table('dark_matter_orders')->insert([
            'id' => 1, 'user_id' => self::PLAYER, 'package' => 'dm_10k', 'dark_matter' => 10_000, 'amount' => 199, 'currency' => 'eur',
            'provider' => 'wax', 'provider_session' => 'xgp:wax-uni:1', 'provider_amount' => 46_676_000_000, 'provider_currency' => 'WAX',
            'expires_at' => self::NOON + 1800, 'status' => DarkMatterOrder::PENDING, 'created_at' => time(),
        ]);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function state(): array
    {
        $darkMatter = DB::table('premium')->where('premium_user_id', self::PLAYER)->value('premium_dark_matter');
        $status = DB::table('dark_matter_orders')->where('id', 1)->value('status');

        return [is_numeric($darkMatter) ? (int) $darkMatter : -1, is_string($status) ? $status : ''];
    }
}
