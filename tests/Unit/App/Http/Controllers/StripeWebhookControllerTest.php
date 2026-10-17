<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers;

use App\Http\Controllers\StripeWebhookController;
use App\Models\DarkMatterOrder;
use App\Services\Premium\DarkMatterOrders;
use App\Services\Premium\StripeSignature;
use App\Services\Premium\StripeWebhookHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Stripe's webhook is what credits bought dark matter. It must only act on
 * signed calls, credit an order exactly once (Stripe sends events again),
 * only for the session, amount and currency the order was opened with, and
 * never touch an order of a session that expired or was refunded otherwise.
 */
#[CoversClass(StripeWebhookController::class)]
#[CoversClass(StripeWebhookHandler::class)]
#[CoversClass(DarkMatterOrders::class)]
class StripeWebhookControllerTest extends DatabaseTestCase
{
    private const SECRET = 'whsec_test_webhook';

    private const PLAYER = 7;

    protected function setUp(): void
    {
        parent::setUp();

        config(['premium.stripe.secret' => 'sk_test_key', 'premium.stripe.webhook_secret' => self::SECRET]);
        DB::table('premium')->insert(['premium_user_id' => self::PLAYER, 'premium_dark_matter' => 500]);
    }

    public function testTheRouteHasNoSessionAndNoGameMiddleware(): void
    {
        $route = Route::getRoutes()->getByName('stripe.webhook');
        $this->assertNotNull($route);

        $middleware = Route::gatherRouteMiddleware($route);

        $this->assertNotContains(StartSession::class, $middleware);
        $this->assertNotContains(\App\Http\Middleware\VerifyCsrfToken::class, $middleware);
        $this->assertNotContains(\App\Http\Middleware\Authenticate::class, $middleware);
        $this->assertSame('stripe/webhook', $route->uri());
    }

    public function testAPaidSessionIsCreditedOnceEvenWhenSentAgain(): void
    {
        $order = $this->order();
        $event = $this->completed($order);

        $this->webhook($event)->assertOk()->assertJson(['result' => 'credited']);
        $this->webhook($event)->assertOk()->assertJson(['result' => 'already credited']);
        // Stripe resends with a fresh signature and another event id
        $event['id'] = 'evt_again';
        $this->webhook($event)->assertOk();

        $this->assertSame(10_500, $this->darkMatter());
        $order->refresh();
        $this->assertSame(DarkMatterOrder::PAID, $order->status);
        $this->assertSame('pi_test_1', $order->provider_payment);
        $this->assertNotNull($order->paid_at);
    }

    public function testAWrongAmountIsNotCredited(): void
    {
        $order = $this->order();

        $this->webhook($this->completed($order, ['amount_total' => 1]))->assertOk()->assertJson(['result' => 'not credited']);

        $this->assertSame(500, $this->darkMatter());
        $this->assertSame(DarkMatterOrder::PENDING, $order->refresh()->status);
    }

    public function testAWrongCurrencyIsNotCredited(): void
    {
        $order = $this->order();

        $this->webhook($this->completed($order, ['currency' => 'usd']))->assertOk();

        $this->assertSame(500, $this->darkMatter());
        $this->assertSame(DarkMatterOrder::PENDING, $order->refresh()->status);
    }

    public function testAnotherSessionDoesNotCreditTheOrder(): void
    {
        $order = $this->order();

        $this->webhook($this->completed($order, ['id' => 'cs_test_other']))->assertOk();

        $this->assertSame(500, $this->darkMatter());
    }

    public function testAnUnpaidSessionWaitsForTheAsyncPayment(): void
    {
        $order = $this->order();

        $this->webhook($this->completed($order, ['payment_status' => 'unpaid']))->assertOk()->assertJson(['result' => 'not paid yet']);
        $this->assertSame(500, $this->darkMatter());

        $event = $this->completed($order);
        $event['type'] = 'checkout.session.async_payment_succeeded';
        $this->webhook($event)->assertOk()->assertJson(['result' => 'credited']);
        $this->assertSame(10_500, $this->darkMatter());
    }

    public function testAnExpiredSessionExpiresThePendingOrderOnly(): void
    {
        $order = $this->order();
        $expired = $this->completed($order, ['payment_status' => 'unpaid', 'status' => 'expired']);
        $expired['type'] = 'checkout.session.expired';

        $this->webhook($expired)->assertOk()->assertJson(['result' => 'expired']);
        $this->assertSame(DarkMatterOrder::EXPIRED, $order->refresh()->status);

        // a late "completed" does not revive it
        $this->webhook($this->completed($order))->assertOk();
        $this->assertSame(500, $this->darkMatter());

        $paid = $this->order('cs_test_2');
        $this->webhook($this->completed($paid))->assertOk();
        $late = $this->completed($paid, ['status' => 'expired']);
        $late['type'] = 'checkout.session.expired';
        $this->webhook($late)->assertOk()->assertJson(['result' => 'ignored']);
        $this->assertSame(DarkMatterOrder::PAID, $paid->refresh()->status);
    }

    public function testAFullRefundTakesBackWhatIsLeft(): void
    {
        $order = $this->order();
        $this->webhook($this->completed($order))->assertOk();
        // the player spent most of it already
        DB::table('premium')->update(['premium_dark_matter' => 3_000]);

        $partial = $this->refunded(false);
        $this->webhook($partial)->assertOk()->assertJson(['result' => 'partial refund ignored']);
        $this->assertSame(3_000, $this->darkMatter());

        $this->webhook($this->refunded(true))->assertOk()->assertJson(['result' => 'refunded']);
        $this->webhook($this->refunded(true))->assertOk()->assertJson(['result' => 'ignored']);

        $this->assertSame(0, $this->darkMatter());
        $this->assertSame(DarkMatterOrder::REFUNDED, $order->refresh()->status);
    }

    public function testABadSignatureIsRefused(): void
    {
        $order = $this->order();
        $payload = (string) json_encode($this->completed($order));

        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=' . time() . ',v1=' . str_repeat('a', 64), 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertStatus(400);
        $this->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)
            ->assertStatus(400);
        // signed, then changed
        $header = (new StripeSignature())->sign($payload, self::SECRET, time());
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'], str_replace('"amount_total":199', '"amount_total":19900', $payload))
            ->assertStatus(400);

        $this->assertSame(500, $this->darkMatter());
    }

    public function testAnOldSignatureIsRefused(): void
    {
        $order = $this->order();

        $this->webhook($this->completed($order), time() - 600)->assertStatus(400);

        $this->assertSame(500, $this->darkMatter());
    }

    public function testWithoutAWebhookSecretNothingIsAccepted(): void
    {
        $order = $this->order();
        $event = $this->completed($order);
        config(['premium.stripe.webhook_secret' => '']);

        $this->webhook($event)->assertStatus(400);

        $this->assertSame(500, $this->darkMatter());
    }

    public function testAnUnknownUniverseIsRefused(): void
    {
        $order = $this->order();

        $this->webhook($this->completed($order, ['metadata' => ['order_id' => (string) $order->id, 'user_id' => (string) self::PLAYER, 'universe' => 'nowhere']]))
            ->assertStatus(400);

        $this->assertSame(500, $this->darkMatter());
    }

    public function testOtherEventsAreAcknowledged(): void
    {
        $this->webhook(['id' => 'evt_x', 'type' => 'customer.created', 'data' => ['object' => []]])->assertOk()->assertJson(['result' => 'ignored']);
    }

    private function order(string $session = 'cs_test_1'): DarkMatterOrder
    {
        return DarkMatterOrder::create([
            'user_id' => self::PLAYER,
            'package' => 'dm_10k',
            'dark_matter' => 10_000,
            'amount' => 199,
            'currency' => 'eur',
            'provider' => 'stripe',
            'provider_session' => $session,
            'status' => DarkMatterOrder::PENDING,
            'created_at' => time(),
        ]);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function completed(DarkMatterOrder $order, array $changes = []): array
    {
        return [
            'id' => 'evt_test_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => array_merge([
                'id' => $order->provider_session,
                'object' => 'checkout.session',
                'amount_total' => 199,
                'currency' => 'eur',
                'client_reference_id' => (string) $order->id,
                'metadata' => ['order_id' => (string) $order->id, 'user_id' => (string) self::PLAYER, 'universe' => ''],
                'payment_intent' => 'pi_test_1',
                'payment_status' => 'paid',
                'status' => 'complete',
            ], $changes)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refunded(bool $full): array
    {
        return [
            'id' => 'evt_refund',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_test_1',
                'object' => 'charge',
                'amount' => 199,
                'amount_refunded' => $full ? 199 : 50,
                'payment_intent' => 'pi_test_1',
                'refunded' => $full,
                'metadata' => [],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function webhook(array $event, ?int $signedAt = null): TestResponse
    {
        $payload = (string) json_encode($event);
        $header = (new StripeSignature())->sign($payload, self::SECRET, $signedAt ?? time());

        return $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    private function darkMatter(): int
    {
        $value = DB::table('premium')->where('premium_user_id', self::PLAYER)->value('premium_dark_matter');

        return is_numeric($value) ? (int) $value : -1;
    }
}
