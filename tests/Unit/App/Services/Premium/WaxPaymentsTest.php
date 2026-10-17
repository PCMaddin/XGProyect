<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Premium;

use App\Models\DarkMatterOrder;
use App\Services\Premium\WaxException;
use App\Services\Premium\WaxPayments;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * A WAX transfer pays an order once: when its block can no longer be
 * undone, to the shop's account, with the order's memo, in full and before
 * the quote ran out. Everything else is kept for the admin and credits
 * nothing. Here on a server without universes (memo xgp:<order>).
 */
#[CoversClass(WaxPayments::class)]
class WaxPaymentsTest extends DatabaseTestCase
{
    use WaxFixtures;

    private const PLAYER = 7;

    private const QUOTE = 46_676_000_000;

    /** @var list<array<string, mixed>> what the history server holds */
    private array $actions = [];

    private int $lastIrreversible = 2000;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        config(['premium.wax.account' => 'xgpshop', 'premium.wax.api_url' => 'https://hyperion.example.org']);
        Http::preventStrayRequests();
        Http::fake([
            'hyperion.example.org/v1/chain/get_info' => fn () => Http::response(['last_irreversible_block_num' => $this->lastIrreversible]),
            'hyperion.example.org/v2/history/get_actions*' => fn (Request $request) => Http::response(['actions' => array_slice($this->actions, (is_numeric($request['skip']) ? (int) $request['skip'] : 0), 100)]),
        ]);

        DB::table('premium')->insert(['premium_user_id' => self::PLAYER, 'premium_dark_matter' => 0]);
        $this->order(1, self::PLAYER);
    }

    public function testAPaymentInFullIsCreditedOnce(): void
    {
        $this->actions = [self::action(['memo' => ' xgp:1 ', 'quantity' => '466.76000000 WAX', 'trx_id' => str_repeat('b', 64)])];

        $this->assertSame(['credited' => 1], $this->check());
        $this->assertSame(10_000, $this->darkMatter());
        $order = DarkMatterOrder::findOrFail(1);
        $this->assertSame([DarkMatterOrder::PAID, str_repeat('b', 64)], [$order->status, $order->provider_payment]);
        $this->assertSame([['sequence' => 5000, 'order_id' => 1, 'result' => 'credited', 'universe' => null, 'units' => self::QUOTE]], $this->transfers());

        // read again: nothing new
        $this->assertSame([], $this->check());
        $this->assertSame(10_000, $this->darkMatter());
        $this->assertCount(1, $this->transfers());
    }

    public function testATransferWaitsUntilItsBlockIsFinal(): void
    {
        $this->actions = [self::action(['block_num' => 1500])];
        $this->lastIrreversible = 1400;

        $this->assertSame([], $this->check());
        $this->assertSame([], $this->transfers());
        $this->assertSame(0, $this->darkMatter());

        $this->lastIrreversible = 1500;
        $this->assertSame(['credited' => 1], $this->check());
        $this->assertSame(10_000, $this->darkMatter());
    }

    public function testWhatDoesNotPayAnOrderIsKeptForTheAdmin(): void
    {
        $this->order(2, self::PLAYER);
        $this->order(3, self::PLAYER, expiresAt: self::NOON - 3600);
        $this->order(4, self::PLAYER + 1);
        $this->order(5, self::PLAYER, provider: 'stripe');

        $this->actions = [
            self::action(['global_sequence' => 1, 'memo' => 'xgp:1', 'quantity' => '466.75000000 WAX']),
            self::action(['global_sequence' => 2, 'memo' => 'xgp:2']),
            self::action(['global_sequence' => 3, 'memo' => 'xgp:2']),
            self::action(['global_sequence' => 4, 'memo' => 'xgp:3']),
            self::action(['global_sequence' => 5, 'memo' => 'thanks!']),
            self::action(['global_sequence' => 6, 'memo' => 'xgp:99']),
            self::action(['global_sequence' => 7, 'memo' => 'xgp:andromeda:2']),
            self::action(['global_sequence' => 8, 'memo' => 'xgp:4']),
            self::action(['global_sequence' => 9, 'memo' => 'xgp:5']),
            // sent from the shop's account, and a token that only calls itself WAX
            self::action(['global_sequence' => 10, 'memo' => 'xgp:1', 'from' => 'xgpshop', 'to' => 'player.wam']),
            self::action(['global_sequence' => 11, 'memo' => 'xgp:1', 'contract' => 'fake.token']),
        ];

        $this->assertSame(['underpaid' => 1, 'credited' => 1, 'not_pending' => 1, 'late' => 1, 'unknown' => 4, 'no_player' => 1], $this->check());

        $this->assertSame(10_000, $this->darkMatter());
        $this->assertSame(
            [1 => 'underpaid', 2 => 'credited', 3 => 'not_pending', 4 => 'late', 5 => 'unknown', 6 => 'unknown', 7 => 'unknown', 8 => 'no_player', 9 => 'unknown'],
            array_column($this->transfers(), 'result', 'sequence')
        );
        // the order of the deleted player stays open for the admin
        $this->assertSame(DarkMatterOrder::PENDING, DarkMatterOrder::findOrFail(4)->status);
        $this->assertSame(DarkMatterOrder::PENDING, DarkMatterOrder::findOrFail(1)->status);
    }

    public function testTheNextCheckGoesOnWhereTheLastStopped(): void
    {
        $this->actions = [self::action(['timestamp' => '2030-01-01T12:10:00.000'])];
        $this->check();

        Http::assertSent(static fn (Request $request): bool => ($request->data()['after'] ?? null) === gmdate('Y-m-d\TH:i:s', self::NOON - 24 * 3600));

        $this->check();
        Http::assertSent(static fn (Request $request): bool => ($request->data()['after'] ?? null) === '2030-01-01T12:08:00');
    }

    public function testManyTransfersAreReadPageByPage(): void
    {
        for ($i = 1; $i <= 150; $i++) {
            $this->actions[] = self::action(['global_sequence' => $i, 'memo' => 'gift ' . $i]);
        }

        $this->assertSame(['unknown' => 150], $this->check());
    }

    public function testWithoutAnAccountNothingIsRead(): void
    {
        config(['premium.wax.account' => 'Not Valid!']);

        $this->expectException(WaxException::class);
        $this->check();
    }

    /**
     * @return array<string, int>
     */
    private function check(): array
    {
        return app(WaxPayments::class)->check(self::NOON);
    }

    private function order(int $id, int $userId, ?int $expiresAt = null, string $provider = 'wax'): void
    {
        DB::table('dark_matter_orders')->insert([
            'id' => $id, 'user_id' => $userId, 'package' => 'dm_10k', 'dark_matter' => 10_000, 'amount' => 199, 'currency' => 'eur',
            'provider' => $provider, 'provider_session' => 'xgp:' . $id, 'provider_amount' => self::QUOTE, 'provider_currency' => 'WAX',
            'expires_at' => $expiresAt ?? self::NOON + 1800, 'status' => DarkMatterOrder::PENDING, 'created_at' => self::NOON - 300,
        ]);
    }

    private function darkMatter(): int
    {
        $value = DB::table('premium')->where('premium_user_id', self::PLAYER)->value('premium_dark_matter');

        return is_numeric($value) ? (int) $value : -1;
    }

    /**
     * @return array<int, array<mixed>>
     */
    private function transfers(): array
    {
        return DB::connection('central')->table('wax_transfers')->orderBy('sequence')->get(['sequence', 'order_id', 'result', 'universe', 'units'])
            ->map(static fn (object $row): array => array_map(static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value, (array) $row))
            ->values()->all();
    }
}
