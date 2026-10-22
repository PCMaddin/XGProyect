<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Premium;

use App\Services\Premium\DarkMatterShop;
use App\Services\Premium\WaxChain;
use App\Services\Premium\WaxException;
use App\Services\SettingsService;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * An alpha takes test payments only (XGP_PAYMENTS_TEST): a live Stripe key
 * or the WAX main network close that way to pay. The shop says when no
 * real money moves, and the WAX history server must be on the chosen chain
 * (a test transfer is worth nothing on the main network, and back).
 */
#[CoversClass(DarkMatterShop::class)]
#[CoversClass(WaxChain::class)]
class TestPaymentsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OptionsTableSeeder::class);
        app(SettingsService::class)->write('premium_mode', 'pay2win');
        app(SettingsService::class)->write('payment_methods', 'stripe,wax');
        config([
            'premium.stripe.webhook_secret' => 'whsec_test',
            'premium.wax.account' => 'shopaccount1',
            'premium.wax.api_url' => 'https://hyperion.example.org',
        ]);
    }

    public function testTestModeOffersOnlyTestKeysAndTheTestNetwork(): void
    {
        config(['premium.test_mode' => true, 'premium.stripe.secret' => 'sk_live_real', 'premium.wax.network' => 'mainnet']);
        $shop = app(DarkMatterShop::class);

        $this->assertFalse($shop->configured(DarkMatterShop::STRIPE));
        $this->assertFalse($shop->configured(DarkMatterShop::WAX));
        $this->assertFalse($shop->isOpen());

        config(['premium.stripe.secret' => 'sk_test_key', 'premium.wax.network' => 'testnet']);

        $this->assertSame([DarkMatterShop::STRIPE, DarkMatterShop::WAX], $shop->methods());
        $this->assertTrue($shop->paysForTest());
    }

    public function testWithoutTestModeLiveKeysWorkAndTheShopSaysWhenNothingIsReal(): void
    {
        config(['premium.test_mode' => false, 'premium.stripe.secret' => 'sk_live_real', 'premium.wax.network' => 'mainnet']);
        $shop = app(DarkMatterShop::class);

        $this->assertSame([DarkMatterShop::STRIPE, DarkMatterShop::WAX], $shop->methods());
        $this->assertFalse($shop->paysForTest());

        // a test key next to WAX on the main network: real money still moves
        config(['premium.stripe.secret' => 'sk_test_key']);
        $this->assertFalse($shop->paysForTest());

        config(['premium.wax.network' => 'testnet']);
        $this->assertTrue($shop->paysForTest());
    }

    public function testTheHistoryServerMustBeOnTheChosenChain(): void
    {
        Http::fake(['hyperion.example.org/v1/chain/get_info' => Http::response(['chain_id' => WaxChain::CHAIN_IDS['mainnet'], 'last_irreversible_block_num' => 99])]);

        config(['premium.wax.network' => 'mainnet']);
        $this->assertSame(99, app(WaxChain::class)->lastIrreversibleBlock());

        config(['premium.wax.network' => 'testnet']);
        $this->expectException(WaxException::class);
        app(WaxChain::class)->lastIrreversibleBlock();
    }

    public function testTheTestNetworkHasItsOwnHistoryServer(): void
    {
        $_SERVER['XGP_WAX_NETWORK'] = 'testnet';

        try {
            $config = require config_path('premium.php');
        } finally {
            unset($_SERVER['XGP_WAX_NETWORK']);
        }

        $this->assertSame('testnet', $config['wax']['network']);
        $this->assertSame('https://testnet.waxsweden.org', $config['wax']['api_url']);
        // no second test network server of another operator by default
        $this->assertSame('', $config['wax']['verify_url']);
    }
}
