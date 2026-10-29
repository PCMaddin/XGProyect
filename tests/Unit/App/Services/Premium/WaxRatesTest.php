<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Premium;

use App\Services\Premium\WaxException;
use App\Services\Premium\WaxRates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Prices are kept in euro; the WAX price follows the rate, rounded up to a
 * hundredth of a WAX so the shop never gets less than the package costs.
 */
#[CoversClass(WaxRates::class)]
class WaxRatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array', 'premium.wax.rate_url' => 'https://rates.example.org/price?ids=wax&vs_currencies=:currency', 'premium.wax.markup' => 0]);
        Http::preventStrayRequests();
    }

    public function testThePriceIsConvertedAtTheRateAndRoundedUp(): void
    {
        Http::fake(['rates.example.org/*' => Http::response(['wax' => ['eur' => 0.00426348]])]);
        $rates = app(WaxRates::class);

        // 1.99 € / 0.00426348 = 466.75… WAX
        $this->assertSame(46_676_000_000, $rates->units(199, 'eur'));
        $this->assertSame('466.76 WAX', WaxRates::format($rates->units(199, 'eur')));
        $this->assertSame('466,76 WAX', WaxRates::format(46_676_000_000, ','));

        config(['premium.wax.markup' => 10]);
        $this->assertSame(51_344_000_000, $rates->units(199, 'eur'));

        // read once, then kept
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://rates.example.org/price?ids=wax&vs_currencies=eur');
    }

    public function testAnExactPriceIsNotRoundedUp(): void
    {
        Http::fake(['rates.example.org/*' => Http::response(['wax' => ['usd' => 0.05]])]);

        $this->assertSame(2_000_000_000, app(WaxRates::class)->units(100, 'usd'));
    }

    public function testARateFarOffTheLastOneIsNotBelieved(): void
    {
        $rate = 0.004;
        Http::fake(['rates.example.org/*' => function () use (&$rate) {
            return Http::response(['wax' => ['eur' => $rate]]);
        }]);
        $rates = app(WaxRates::class);

        $this->assertSame(0.004, $rates->rate('eur'));
        Cache::forget('wax_rate_eur');

        // a broken or forged feed: WAX a hundred times dearer, dark matter nearly free
        $rate = 0.4;

        try {
            $rates->rate('eur');
            $this->fail('the jump was believed');
        } catch (WaxException) {
            $this->assertNull(Cache::get('wax_rate_eur'));
        }

        // a move within the limit counts, and a day later any rate counts again
        $rate = 0.005;
        $this->assertSame(0.005, $rates->rate('eur'));
        Cache::forget('wax_rate_eur');
        $rate = 0.05;
        $this->travel(25)->hours();
        $this->assertSame(0.05, $rates->rate('eur'));
    }

    public function testWithoutARateThereIsNoPrice(): void
    {
        Http::fake(['rates.example.org/*' => Http::sequence()
            ->push(['wax' => []])
            ->push(['wax' => ['eur' => 0]])
            ->push('Too Many Requests', 429)]);

        for ($i = 0; $i < 3; $i++) {
            try {
                app(WaxRates::class)->units(199, 'eur');
                $this->fail('no exception');
            } catch (WaxException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
