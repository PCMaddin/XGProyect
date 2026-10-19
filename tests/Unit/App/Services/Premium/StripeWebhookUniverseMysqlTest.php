<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Premium;

use App\Models\DarkMatterOrder;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use App\Services\Premium\StripeSignature;
use App\Services\Premium\StripeWebhookHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Stripe calls one webhook for every universe of the server. The order and
 * the player live in the universe's own database, so the webhook switches
 * to the universe named in the session's metadata before it reads or
 * credits anything; an order of the same number in another database stays
 * untouched, and an unknown universe is refused.
 *
 * The universe's database is xgp_test_shop, created and dropped here.
 */
#[CoversClass(StripeWebhookHandler::class)]
class StripeWebhookUniverseMysqlTest extends MysqlTestCase
{
    private const UNIVERSE_DATABASE = 'xgp_test_shop';

    private const SECRET = 'whsec_test_universe';

    private const PLAYER = 7;

    protected function setUp(): void
    {
        parent::setUp();

        config(['premium.stripe.secret' => 'sk_test_key', 'premium.stripe.webhook_secret' => self::SECRET]);

        // the registry of the universes is in the base database
        config(['database.connections.central' => config('database.connections.mysql_test')]);
        DB::purge('central');
        Artisan::call('migrate', ['--path' => 'database/migrations/central', '--force' => true]);

        DB::statement('DROP DATABASE IF EXISTS `' . self::UNIVERSE_DATABASE . '`');
        DB::statement('CREATE DATABASE `' . self::UNIVERSE_DATABASE . '`');

        $universe = $this->universe();
        app(InstanceRegistry::class)->register($universe);
        app(InstanceContext::class)->runIn($universe, function (): void {
            Artisan::call('migrate', ['--path' => 'database/migrations/install', '--force' => true]);
            $this->player();
        });

        // the same player and order number in the base database
        $this->player();
    }

    protected function tearDown(): void
    {
        // skipped without MYSQL_TEST_DATABASE: nothing was created
        if (config('database.default') === 'mysql_test') {
            app(InstanceContext::class)->reset();
            DB::statement('DROP DATABASE IF EXISTS `' . self::UNIVERSE_DATABASE . '`');
        }

        parent::tearDown();
    }

    public function testThePaymentIsCreditedInTheUniverseOfTheOrder(): void
    {
        $this->webhook($this->completed('shop'))->assertOk()->assertJson(['result' => 'credited']);
        $this->webhook($this->completed('shop'))->assertOk()->assertJson(['result' => 'already credited']);

        $this->assertSame([10_000, DarkMatterOrder::PAID], $this->inUniverse(fn (): array => $this->state()));
        // the base database is another universe's: untouched
        $this->assertSame([0, DarkMatterOrder::PENDING], $this->state());
        // and the request is back on the base database
        $this->assertNull(app(InstanceContext::class)->current());
    }

    public function testAnUnknownUniverseIsRefused(): void
    {
        $this->webhook($this->completed('nowhere'))->assertStatus(400);

        $this->assertSame([0, DarkMatterOrder::PENDING], $this->inUniverse(fn (): array => $this->state()));
        $this->assertSame([0, DarkMatterOrder::PENDING], $this->state());
    }

    public function testAnArchivedUniverseIsReportedAndNotTouched(): void
    {
        DB::connection('central')->table('instances')->where('slug', 'shop')->update(['status' => Instance::ARCHIVED, 'archive' => 'restic:abc']);

        // 200: Stripe stops sending it; the operators see it among the errors
        $this->webhook($this->completed('shop'))->assertOk()->assertJson(['result' => 'universe archived']);

        $this->assertSame([0, DarkMatterOrder::PENDING], $this->inUniverse(fn (): array => $this->state()));
        $this->assertSame([0, DarkMatterOrder::PENDING], $this->state());
        $reported = DB::connection('central')->table('error_groups')->value('message');
        $this->assertIsString($reported);
        $this->assertStringContainsString('archived universe shop', $reported);
    }

    public function testARefundFindsTheUniverseOfThePayment(): void
    {
        $this->webhook($this->completed('shop'))->assertOk();

        // the charge does not carry the session's metadata
        $this->webhook([
            'id' => 'evt_refund',
            'type' => 'charge.refunded',
            'data' => ['object' => ['id' => 'ch_1', 'payment_intent' => 'pi_universe', 'refunded' => true, 'metadata' => []]],
        ])->assertOk()->assertJson(['result' => 'refunded']);

        $this->assertSame([0, DarkMatterOrder::REFUNDED], $this->inUniverse(fn (): array => $this->state()));
        $this->assertSame([0, DarkMatterOrder::PENDING], $this->state());
    }

    private function universe(): Instance
    {
        return new Instance('shop', 'Shop', self::UNIVERSE_DATABASE, Instance::PAY2WIN, Instance::OPEN, time());
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function inUniverse(callable $work): mixed
    {
        return app(InstanceContext::class)->runIn($this->universe(), $work);
    }

    private function player(): void
    {
        DB::table('premium')->insert(['premium_user_id' => self::PLAYER, 'premium_dark_matter' => 0]);
        DB::table('dark_matter_orders')->insert([
            'id' => 1, 'user_id' => self::PLAYER, 'package' => 'dm_10k', 'dark_matter' => 10_000, 'amount' => 199, 'currency' => 'eur',
            'provider' => 'stripe', 'provider_session' => 'cs_universe', 'status' => DarkMatterOrder::PENDING, 'created_at' => time(),
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

    /**
     * @return array<string, mixed>
     */
    private function completed(string $universe): array
    {
        return [
            'id' => 'evt_universe',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_universe',
                'amount_total' => 199,
                'currency' => 'eur',
                'client_reference_id' => '1',
                'metadata' => ['order_id' => '1', 'user_id' => (string) self::PLAYER, 'universe' => $universe],
                'payment_intent' => 'pi_universe',
                'payment_status' => 'paid',
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function webhook(array $event): TestResponse
    {
        $payload = (string) json_encode($event);
        $header = (new StripeSignature())->sign($payload, self::SECRET, time());

        return $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'], $payload);
    }
}
