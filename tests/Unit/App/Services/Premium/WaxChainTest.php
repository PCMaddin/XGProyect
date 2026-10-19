<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Premium;

use App\Services\Premium\WaxChain;
use App\Services\Premium\WaxException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Only transfers of the real WAX count: the token of eosio.token with its
 * eight decimals. A token of another contract that calls itself WAX is
 * worth nothing and must never pay for dark matter.
 */
#[CoversClass(WaxChain::class)]
class WaxChainTest extends TestCase
{
    use WaxFixtures;

    public function testATransferOfWaxIsRead(): void
    {
        $transfer = WaxChain::transfer(self::action());

        $this->assertNotNull($transfer);
        $this->assertSame(5000, $transfer->sequence);
        $this->assertSame(1000, $transfer->blockNum);
        $this->assertSame(self::NOON, $transfer->blockTime);
        $this->assertSame('player.wam', $transfer->from);
        $this->assertSame('xgpshop', $transfer->to);
        $this->assertSame(46_725_000_000, $transfer->units);
        $this->assertSame('xgp:1', $transfer->memo);
    }

    public function testOnlyTheSystemTokenCounts(): void
    {
        $this->assertNull(WaxChain::transfer(self::action(['contract' => 'fake.token'])));
        $this->assertNull(WaxChain::transfer(self::action(['name' => 'issue'])));
        $this->assertNull(WaxChain::transfer(self::action(['quantity' => '467.25 WAX'])));
        $this->assertNull(WaxChain::transfer(self::action(['quantity' => '467.25000000 WAXP'])));
        $this->assertNull(WaxChain::transfer(self::action(['quantity' => '0.00000000 WAX'])));
        $this->assertNull(WaxChain::transfer(self::action(['global_sequence' => '5000'])));
        $this->assertNull(WaxChain::transfer(self::action(['from' => null])));
        $this->assertNull(WaxChain::transfer(['act' => 'nonsense']));
    }

    public function testQuantitiesAreExact(): void
    {
        $this->assertSame(1, WaxChain::units('0.00000001 WAX'));
        $this->assertSame(100_000_000, WaxChain::units('1.00000000 WAX'));
        $this->assertSame(123_456_789_012_345_678, WaxChain::units('1234567890.12345678 WAX'));
        $this->assertNull(WaxChain::units('-1.00000000 WAX'));
        $this->assertNull(WaxChain::units('1,00000000 WAX'));
    }

    public function testTheHistoryServerIsAskedForTheAccountsTransfers(): void
    {
        config(['premium.wax.api_url' => 'https://hyperion.example.org/']);
        Http::fake([
            'hyperion.example.org/v1/chain/get_info' => Http::response(['chain_id' => WaxChain::CHAIN_IDS['mainnet'], 'head_block_num' => 1200, 'last_irreversible_block_num' => 1100]),
            'hyperion.example.org/v2/history/get_actions*' => Http::response(['lib' => 0, 'actions' => [self::action(), self::action(['contract' => 'fake.token'])]]),
        ]);

        $chain = new WaxChain();
        $this->assertSame(1100, $chain->lastIrreversibleBlock());
        $page = $chain->transfers('xgpshop', self::NOON - 3600, 100);

        $this->assertCount(1, $page['transfers']);
        $this->assertSame(2, $page['actions']);
        Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), 'https://hyperion.example.org/v2/history/get_actions?') &&
            $request['account'] === 'xgpshop' &&
            $request['filter'] === 'eosio.token:transfer' &&
            $request['sort'] === 'asc' &&
            $request['after'] === '2030-01-01T11:00:00' &&
            (is_numeric($request['skip']) ? (int) $request['skip'] : 0) === 100);
    }

    public function testAServerThatFailsIsAWaxException(): void
    {
        Http::fake(['*/v1/chain/get_info' => Http::response('busy', 429), '*/v2/*' => fn () => throw new ConnectionException('timeout')]);
        $chain = new WaxChain();

        foreach ([fn () => $chain->lastIrreversibleBlock(), fn () => $chain->transfers('xgpshop', 0, 0)] as $call) {
            try {
                $call();
                $this->fail('no exception');
            } catch (WaxException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
