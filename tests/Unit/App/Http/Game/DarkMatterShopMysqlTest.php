<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Game;

use App\Http\Controllers\Game\DarkMatterShopController;
use App\Libraries\Users;
use App\Models\DarkMatterOrder;
use App\Models\User;
use App\Services\Premium\StripeSignature;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Tests\MysqlTestCase;

/**
 * The whole purchase through the game's pages: the premium page leads to
 * the shop, the shop lists the packages, buying one sends the player to
 * Stripe, and the order's page shows the payment pending until Stripe's
 * webhook credits it. In a Play2Win universe or without the Stripe keys
 * there is no button and the shop refuses.
 */
#[CoversClass(DarkMatterShopController::class)]
class DarkMatterShopMysqlTest extends MysqlTestCase
{
    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0',
            'premium.stripe.secret' => 'sk_test_key', 'premium.stripe.webhook_secret' => 'whsec_test_game',
        ]);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        // the tick's maintenance has just run
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);
        app(SettingsService::class)->write('premium_mode', 'pay2win');

        $player = app(RegistrationService::class)->register(['name' => 'Buyer', 'email' => 'buyer@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $player);
        $this->player = $player;

        Http::preventStrayRequests();
    }

    public function testAPurchaseFromTheButtonToTheCredit(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_game', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_game'])]);
        $before = $this->darkMatter();

        $this->asPlayer()->get('/game.php?page=premium')->assertOk()->assertSee("href='game.php?page=darkmatter'", false);

        $this->asPlayer()->get('/game.php?page=darkmatter')->assertOk()
            ->assertSee('Buy dark matter')
            ->assertSee('120.000')
            ->assertSee('16.99 €')
            ->assertSee('name="package" value="dm_120k"', false)
            ->assertSee('name="_token"', false);

        $this->asPlayer()->post('/game.php?page=darkmatter', ['package' => 'dm_120k'])
            ->assertStatus(303)
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_game');

        $order = DarkMatterOrder::sole();
        $this->assertSame([DarkMatterOrder::PENDING, 1699, 120_000], [$order->status, $order->amount, $order->dark_matter]);
        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'metadata%5Buniverse%5D=&') &&
            str_contains($request->body(), 'success_url=' . urlencode(url('game.php') . '?page=darkmatter&order=' . $order->id)));

        // back from Stripe before the webhook arrived
        $this->asPlayer()->get('/game.php?page=darkmatter&order=' . $order->id)->assertOk()
            ->assertSee('Waiting for the payment confirmation')
            ->assertSee('http-equiv="refresh"', false);

        $this->webhook([
            'id' => 'evt_game', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_game', 'amount_total' => 1699, 'currency' => 'eur', 'payment_status' => 'paid', 'payment_intent' => 'pi_game',
                'client_reference_id' => (string) $order->id, 'metadata' => ['order_id' => (string) $order->id, 'user_id' => (string) $this->player->id, 'universe' => ''],
            ]],
        ]);

        $this->assertSame($before + 120_000, $this->darkMatter());
        $this->asPlayer()->get('/game.php?page=darkmatter&order=' . $order->id)->assertOk()
            ->assertSee('120.000 dark matter have been credited to your account.')
            ->assertDontSee('http-equiv="refresh"', false);
    }

    public function testStripeDownShowsAMessageNotAnError(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401)]);

        $this->asPlayer()->post('/game.php?page=darkmatter', ['package' => 'dm_10k'])
            ->assertOk()
            ->assertSee('The payment service cannot be reached right now.');

        $this->assertSame(DarkMatterOrder::FAILED, DarkMatterOrder::sole()->status);
    }

    public function testPlay2WinHasNoButtonAndNoShop(): void
    {
        app(SettingsService::class)->write('premium_mode', 'play2win');

        $this->assertClosed();
    }

    public function testWithoutStripeKeysThereIsNoShop(): void
    {
        config(['premium.stripe.secret' => '']);

        $this->assertClosed();
    }

    private function assertClosed(): void
    {
        Http::fake();

        $this->asPlayer()->get('/game.php?page=premium')->assertOk()->assertDontSee('page=darkmatter', false);
        $this->asPlayer()->get('/game.php?page=darkmatter')->assertOk()
            ->assertSee('Dark matter cannot be bought in this universe.')
            ->assertDontSee('dm_10k');
        $this->asPlayer()->post('/game.php?page=darkmatter', ['package' => 'dm_10k'])->assertOk()
            ->assertSee('Dark matter cannot be bought in this universe.');

        Http::assertNothingSent();
        $this->assertSame(0, DarkMatterOrder::count());
    }

    /**
     * Logged in as the game does it (the session login data the player
     * library reads), with that library built afresh for the request.
     */
    private function asPlayer(): static
    {
        (new ReflectionProperty(Users::class, 'instance'))->setValue(null, null);

        $password = User::findOrFail($this->player->id)->getAuthPassword();

        return $this->actingAs($this->player)->withSession([
            'user_id' => $this->player->id,
            'user_password' => Hash::make($password . '-test-secret'),
        ]);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function webhook(array $event): void
    {
        $payload = (string) json_encode($event);
        $header = (new StripeSignature())->sign($payload, 'whsec_test_game', time());

        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertOk()
            ->assertJson(['result' => 'credited']);
    }

    private function darkMatter(): int
    {
        $value = DB::table('premium')->where('premium_user_id', $this->player->id)->value('premium_dark_matter');

        return is_numeric($value) ? (int) $value : -1;
    }
}
