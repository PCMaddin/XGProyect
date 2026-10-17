<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Premium;

use App\Services\Premium\WaxException;
use App\Services\Premium\WaxRates;
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
