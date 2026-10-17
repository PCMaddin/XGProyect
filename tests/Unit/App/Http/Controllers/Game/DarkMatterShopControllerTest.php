<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Exceptions\HaltWithView;
use App\Http\Controllers\Game\DarkMatterShopController;
use App\Models\DarkMatterOrder;
use App\Models\User;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Premium\DarkMatterShop;
use App\Services\Premium\StripeCheckout;
use App\Services\SettingsService;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\DatabaseTestCase;

/**
 * The dark matter shop exists only in a Pay2Win universe with the Stripe
 * keys set; otherwise there is no button and the page refuses. A purchase
 * takes the package (amount, price, currency) from the configuration, never
 * from the player, opens a pending order and a Stripe Checkout session that
 * names the order, the player and the universe, and sends the player there.
 * Stripe being down shows a message, never an error page.
 */
#[CoversClass(DarkMatterShopController::class)]
#[CoversClass(DarkMatterShop::class)]
#[CoversClass(StripeCheckout::class)]
class DarkMatterShopControllerTest extends DatabaseTestCase
{
    private const PLAYER = 7;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OptionsTableSeeder::class);
        config(['premium.stripe.secret' => 'sk_test_key', 'premium.stripe.webhook_secret' => 'whsec_test', 'premium.currency' => 'eur']);
        $this->mode('pay2win');
        Http::preventStrayRequests();
    }

    public function testPay2WinWithStripeOpensTheShop(): void
    {
        $shop = app(DarkMatterShop::class);

        $this->assertTrue($shop->isOpen());
        $this->assertSame('game.php?page=darkmatter', $shop->buyUrl());
        $this->assertSame(['dm_10k', 'dm_50k', 'dm_120k', 'dm_300k'], array_keys($shop->packages()));
        $this->assertSame(799, $shop->package('dm_50k')?->price);
    }

    public function testPlay2WinHasNoShopAndNoButtonInAUniverse(): void
    {
        $this->mode('play2win');
        app(SettingsService::class)->write('premium_url', 'https://shop.example.org/dm');

        $shop = app(DarkMatterShop::class);
        $this->assertFalse($shop->isOpen());
        // a server without universes keeps the link it had
        $this->assertSame('https://shop.example.org/dm', $shop->buyUrl());

        $this->inUniverse('andromeda');
        $this->assertSame('', app(DarkMatterShop::class)->buyUrl());
    }

    public function testWithoutStripeKeysPay2WinLinksTheOwnShopOnly(): void
    {
        config(['premium.stripe.webhook_secret' => '']);
        $this->inUniverse('andromeda');

        $shop = app(DarkMatterShop::class);
        $this->assertFalse($shop->isOpen());
        $this->assertSame('', $shop->buyUrl());

        app(SettingsService::class)->write('premium_url', 'https://shop.example.org/dm');
        $this->assertSame('https://shop.example.org/dm', app(DarkMatterShop::class)->buyUrl());
    }

    public function testBrokenPackagesAreLeftOut(): void
    {
        config(['premium.packages' => [
            ['id' => 'ok', 'dark_matter' => 5, 'price' => 100],
            ['id' => 'free', 'dark_matter' => 5, 'price' => 0],
            ['id' => 'Bad Id', 'dark_matter' => 5, 'price' => 100],
            ['id' => 'text', 'dark_matter' => '5', 'price' => 100],
            ['id' => 'ok', 'dark_matter' => 9, 'price' => 1],
            'nonsense',
        ], 'premium.currency' => 'euro']);

        $shop = app(DarkMatterShop::class);

        $this->assertSame(['ok'], array_keys($shop->packages()));
        $this->assertSame(5, $shop->package('ok')?->darkMatter);
        $this->assertSame('eur', $shop->currency());

        config(['premium.packages' => []]);
        $this->assertFalse(app(DarkMatterShop::class)->isOpen());
    }

    public function testPricesAreShownInTheLanguagesFormat(): void
    {
        $shop = app(DarkMatterShop::class);

        app()->setLocale('de');
        $this->assertSame('16,99 €', $shop->formatPrice(1699, 'eur'));
        app()->setLocale('en');
        $this->assertSame('1.99 $', $shop->formatPrice(199, 'usd'));
        $this->assertSame('5.00 CHF', $shop->formatPrice(500, 'chf'));
    }

    public function testTheShopListsThePackages(): void
    {
        $view = $this->page(Request::create('/game.php?page=darkmatter'));

        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $view);
        $packages = $view->getData()['packages'];
        $this->assertIsArray($packages);
        $this->assertCount(4, $packages);
        $this->assertSame(['id' => 'dm_10k', 'dark_matter' => '10.000', 'price' => '1.99 €'], $packages[0]);
    }

    public function testAClosedShopRefusesEveryRequest(): void
    {
        Http::fake();

        foreach (['play2win', 'pay2win-without-keys'] as $case) {
            if ($case === 'play2win') {
                $this->mode('play2win');
            } else {
                $this->mode('pay2win');
                config(['premium.stripe.secret' => '']);
            }

            $this->assertRefused(Request::create('/game.php?page=darkmatter'), 'game/darkmatter.dm_shop_closed');
            $this->assertRefused(Request::create('/game.php?page=darkmatter', 'POST', ['package' => 'dm_10k']), 'game/darkmatter.dm_shop_closed');
        }

        Http::assertNothingSent();
        $this->assertSame(0, DarkMatterOrder::count());
    }

    public function testBuyingOpensACheckoutSessionWithTheConfiguredPrice(): void
    {
        $this->inUniverse('andromeda');
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_new', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_new'])]);

        // what the player sends besides the package is ignored
        $response = $this->page(Request::create('https://game.example.org/game.php?page=darkmatter', 'POST', ['package' => 'dm_50k', 'price' => '1', 'dark_matter' => '999999']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_new', $response->getTargetUrl());

        $order = DarkMatterOrder::sole();
        $this->assertSame(['user_id' => self::PLAYER, 'package' => 'dm_50k', 'dark_matter' => 50_000, 'amount' => 799, 'currency' => 'eur', 'provider_session' => 'cs_test_new', 'status' => 'pending'], [
            'user_id' => $order->user_id, 'package' => $order->package, 'dark_matter' => $order->dark_matter, 'amount' => $order->amount,
            'currency' => $order->currency, 'provider_session' => $order->provider_session, 'status' => $order->status,
        ]);

        $back = 'https://game.example.org/game.php?page=darkmatter&order=' . $order->id;
        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request) use ($order, $back): bool {
            // what goes over the wire, form-encoded as Stripe expects it
            parse_str($request->body(), $data);
            $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('Authorization', 'Basic ' . base64_encode('sk_test_key:')));
            $this->assertTrue($request->hasHeader('Idempotency-Key', 'xgp-dm-andromeda-' . $order->id));
            $this->assertSame('payment', $data['mode']);
            $this->assertSame([[
                'quantity' => '1',
                'price_data' => ['currency' => 'eur', 'unit_amount' => '799', 'product_data' => ['name' => '50.000 dark matter']],
            ]], $data['line_items']);
            $this->assertSame((string) $order->id, $data['client_reference_id']);
            $metadata = ['order_id' => (string) $order->id, 'user_id' => (string) self::PLAYER, 'universe' => 'andromeda'];
            $this->assertSame($metadata, $data['metadata']);
            $this->assertSame(['metadata' => $metadata], $data['payment_intent_data']);
            $this->assertSame($back, $data['success_url']);
            $this->assertSame($back . '&cancelled=1', $data['cancel_url']);

            return true;
        });
    }

    public function testAnUnknownPackageIsRefused(): void
    {
        Http::fake();

        $this->assertRefused(Request::create('/game.php?page=darkmatter', 'POST', ['package' => 'dm_free']), 'game/darkmatter.dm_unknown_package');
        $this->assertRefused(Request::create('/game.php?page=darkmatter', 'POST'), 'game/darkmatter.dm_unknown_package');

        Http::assertNothingSent();
        $this->assertSame(0, DarkMatterOrder::count());
    }

    public function testStripeRefusingShowsAMessageAndFailsTheOrder(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401)]);

        $this->assertRefused(Request::create('/game.php?page=darkmatter', 'POST', ['package' => 'dm_10k']), 'game/darkmatter.dm_checkout_failed');

        $this->assertSame(DarkMatterOrder::FAILED, DarkMatterOrder::sole()->status);
    }

    public function testStripeUnreachableShowsAMessageAndFailsTheOrder(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host: api.stripe.com'));

        $this->assertRefused(Request::create('/game.php?page=darkmatter', 'POST', ['package' => 'dm_10k']), 'game/darkmatter.dm_checkout_failed');

        $this->assertSame(DarkMatterOrder::FAILED, DarkMatterOrder::sole()->status);
    }

    public function testOpeningOrdersInALoopIsStopped(): void
    {
        Http::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->order(DarkMatterOrder::PENDING, self::PLAYER);
        }

        $this->assertRefused(Request::create('/game.php?page=darkmatter', 'POST', ['package' => 'dm_10k']), 'game/darkmatter.dm_too_many_orders');

        Http::assertNothingSent();
        $this->assertSame(5, DarkMatterOrder::count());
    }

    public function testTheOrderPageShowsTheStatusOfTheOwnOrderOnly(): void
    {
        $pending = $this->order(DarkMatterOrder::PENDING, self::PLAYER);
        $paid = $this->order(DarkMatterOrder::PAID, self::PLAYER);
        $foreign = $this->order(DarkMatterOrder::PAID, self::PLAYER + 1);

        $view = $this->page(Request::create('/game.php?page=darkmatter&order=' . $pending->id));
        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $view);
        $this->assertSame('pending', $view->getData()['status']);
        $this->assertTrue($view->getData()['refresh']);

        $cancelled = $this->page(Request::create('/game.php?page=darkmatter&cancelled=1&order=' . $pending->id));
        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $cancelled);
        $this->assertTrue($cancelled->getData()['cancelled']);
        $this->assertFalse($cancelled->getData()['refresh']);

        $view = $this->page(Request::create('/game.php?page=darkmatter&order=' . $paid->id));
        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $view);
        $this->assertSame('10.000 dark matter have been credited to your account.', $view->getData()['status_text']);

        $this->assertRefused(Request::create('/game.php?page=darkmatter&order=' . $foreign->id), 'game/darkmatter.dm_order_not_found');
    }

    private function page(Request $request): Response | \Illuminate\Contracts\View\View
    {
        $user = new User();
        $user->id = self::PLAYER;
        // binding the request sets the user resolver of the auth guard
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $user);

        return app(DarkMatterShopController::class)($request);
    }

    private function assertRefused(Request $request, string $message): void
    {
        try {
            $this->page($request);
            $this->fail('the page did not refuse');
        } catch (HaltWithView $halt) {
            $this->assertSame(__($message), $halt->getView()->getData()['mes']);
        }
    }

    private function mode(string $mode): void
    {
        app(SettingsService::class)->write('premium_mode', $mode);
    }

    private function inUniverse(string $slug): void
    {
        $context = $this->createStub(InstanceContext::class);
        $context->method('current')->willReturn(new Instance($slug, ucfirst($slug), 'xgp_' . $slug, Instance::PAY2WIN, Instance::OPEN, time()));
        $this->app->instance(InstanceContext::class, $context);
    }

    private function order(string $status, int $userId): DarkMatterOrder
    {
        $id = DB::table('dark_matter_orders')->insertGetId([
            'user_id' => $userId, 'package' => 'dm_10k', 'dark_matter' => 10_000, 'amount' => 199, 'currency' => 'eur',
            'provider' => 'stripe', 'status' => $status, 'created_at' => time(),
        ]);

        return DarkMatterOrder::findOrFail($id);
    }
}
