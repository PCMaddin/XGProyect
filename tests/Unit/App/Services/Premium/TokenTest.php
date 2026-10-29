<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Premium;

use App\Services\Premium\TokenRates;
use App\Services\Premium\TokenSpec;
use App\Services\Premium\WaxChain;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The game's own token: exact quantities, a fixed rate of dark matter, and
 * only transfers of its own contract count; a token of another contract
 * with the same symbol is worth nothing.
 */
#[CoversClass(TokenSpec::class)]
#[CoversClass(TokenRates::class)]
#[CoversClass(WaxChain::class)]
class TokenTest extends TestCase
{
    use WaxFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config(['premium.token' => [
            'contract' => 'xgptokens', 'symbol' => 'XGP', 'decimals' => 4, 'dark_matter_per_token' => 100,
            'market_url' => 'https://alcor.example.org/api/v2/tokens/:id', 'quote_hours' => 24,
        ]]);
    }

    public function testTheTokenComesFromTheConfiguration(): void
    {
        $token = TokenSpec::game();

        $this->assertNotNull($token);
        $this->assertSame(['xgptokens', 'XGP', 4], [$token->contract, $token->symbol, $token->decimals]);

        foreach ([['contract' => ''], ['contract' => 'Not.Valid'], ['symbol' => ''], ['symbol' => 'xgp'], ['decimals' => 9]] as $broken) {
            config(['premium.token' => array_merge((array) config('premium.token'), $broken)]);
            $this->assertNull(TokenSpec::game(), (string) json_encode($broken));
            config(['premium.token' => ['contract' => 'xgptokens', 'symbol' => 'XGP', 'decimals' => 4]]);
        }
    }

    public function testQuantitiesAreExact(): void
    {
        $token = new TokenSpec('xgptokens', 'XGP', 4);

        $this->assertSame(125_000, $token->units('12.5000 XGP'));
        $this->assertSame(1, $token->units('0.0001 XGP'));
        $this->assertNull($token->units('12.5 XGP'));
        $this->assertNull($token->units('12.5000 WAX'));
        $this->assertNull($token->units('-1.0000 XGP'));
        $this->assertSame('12.5000 XGP', $token->quantity(125_000));
        $this->assertSame('12,50 XGP', $token->format(125_000, ','));
        $this->assertSame(7, (new TokenSpec('xgptokens', 'XGP', 0))->units('7 XGP'));
    }

    public function testDarkMatterHasAFixedPriceInTokens(): void
    {
        $rates = app(TokenRates::class);
        $token = new TokenSpec('xgptokens', 'XGP', 4);

        // 100 dark matter per token: 10,000 cost 100 tokens
        $this->assertSame(1_000_000, $rates->unitsFor(10_000, $token));
        // the shop rounds up, a prize down
        config(['premium.token.dark_matter_per_token' => 3]);
        $this->assertSame(3334, $rates->unitsFor(1, $token));
        $this->assertSame(3333, $rates->unitsForPrize(1, $token));
    }

    public function testTheMarketPriceIsReadOnceInAWhile(): void
    {
        Http::fake(['alcor.example.org/*' => Http::response(['system_price' => 0.25, 'usd_price' => 0.01])]);
        $rates = app(TokenRates::class);
        $token = new TokenSpec('xgptokens', 'XGP', 4);

        $this->assertSame(0.25, $rates->marketWax($token));
        $this->assertSame(0.25, $rates->marketWax($token));
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://alcor.example.org/api/v2/tokens/xgp-xgptokens');
    }

    public function testAnUnknownMarketPriceIsNone(): void
    {
        Http::fake(['alcor.example.org/*' => Http::response('not found', 404)]);

        $this->assertNull(app(TokenRates::class)->marketWax(new TokenSpec('xgptokens', 'XGP', 4)));
    }

    public function testOnlyTheTokensOwnContractCounts(): void
    {
        $tokens = [TokenSpec::wax(), new TokenSpec('xgptokens', 'XGP', 4)];

        $transfer = WaxChain::transfer(self::action(['contract' => 'xgptokens', 'quantity' => '100.0000 XGP']), $tokens);
        $this->assertNotNull($transfer);
        $this->assertSame(['XGP', 1_000_000], [$transfer->symbol, $transfer->units]);
        $this->assertSame('WAX', WaxChain::transfer(self::action(), $tokens)?->symbol);

        // the same symbol from another contract, the token with WAX's contract, wrong decimals
        $this->assertNull(WaxChain::transfer(self::action(['contract' => 'fake.token', 'quantity' => '100.0000 XGP']), $tokens));
        $this->assertNull(WaxChain::transfer(self::action(['contract' => 'eosio.token', 'quantity' => '100.0000 XGP']), $tokens));
        $this->assertNull(WaxChain::transfer(self::action(['contract' => 'xgptokens', 'quantity' => '100.00000000 XGP']), $tokens));
        // without the game's token only WAX counts
        $this->assertNull(WaxChain::transfer(self::action(['contract' => 'xgptokens', 'quantity' => '100.0000 XGP'])));
    }

    public function testTheHistoryServerIsAskedForBothContracts(): void
    {
        config(['premium.wax.api_url' => 'https://hyperion.example.org']);
        Http::fake(['hyperion.example.org/v2/history/get_actions*' => Http::response(['actions' => []])]);

        (new WaxChain())->transfers('xgpshop', self::NOON, 0, [TokenSpec::wax(), new TokenSpec('xgptokens', 'XGP', 4)]);

        Http::assertSent(static fn (Request $request): bool => $request['filter'] === 'eosio.token:transfer,xgptokens:transfer');
    }
}
