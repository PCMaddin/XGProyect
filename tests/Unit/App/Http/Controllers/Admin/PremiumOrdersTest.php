<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\PremiumController;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The admin sees what was bought in the universe (who, which package, the
 * state of the payment, when) and the revenue of the paid orders, and sets
 * whether the universe is Pay2Win or Play2Win; a universe of several gets
 * its mode from the command line instead.
 */
#[CoversClass(PremiumController::class)]
class PremiumOrdersTest extends DatabaseTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);

        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'home_planet_id' => 1, 'current_planet' => 1]);
        $admin->authlevel = 3;
        $admin->save();
        $this->admin = $admin;
    }

    public function testTheOrdersAndTheRevenueAreListed(): void
    {
        $this->order($this->admin->id, 'dm_10k', 199, 'paid');
        $this->order($this->admin->id, 'dm_50k', 799, 'pending');
        $this->order($this->admin->id, 'dm_120k', 1699, 'paid');
        $this->order(999, 'dm_300k', 3499, 'refunded');

        $this->actingAs($this->admin)->get('/admin/premium/orders')
            ->assertOk()
            ->assertSee('Dark matter orders')
            ->assertSee('18.98 €')
            ->assertSee('dm_300k')
            ->assertSee('deleted player (999)')
            ->assertSee('refunded')
            ->assertSee('Admin');
    }

    public function testTokenOrdersCountInTokensNotInMoney(): void
    {
        config(['premium.token' => ['contract' => 'xgptokens', 'symbol' => 'XGP', 'decimals' => 4]]);
        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        $this->order($this->admin->id, 'dm_10k', 199, 'paid');
        DB::table('dark_matter_orders')->insert([
            'user_id' => $this->admin->id, 'package' => 'dm_50k', 'dark_matter' => 50_000, 'amount' => 799, 'currency' => 'eur', 'provider' => 'token',
            'provider_amount' => 5_000_000, 'provider_currency' => 'XGP', 'provider_session' => 'xgp:2', 'status' => 'paid', 'created_at' => time(), 'paid_at' => time(),
        ]);
        DB::connection('central')->table('wax_transfers')->insert([
            'sequence' => 1, 'trx_id' => str_repeat('a', 64), 'block_num' => 10, 'block_time' => time(), 'from_account' => 'player.wam', 'units' => 5_000_000,
            'symbol' => 'XGP', 'memo' => 'xgp:2', 'universe' => null, 'order_id' => 2, 'result' => 'credited', 'checked_at' => time(),
        ]);

        $this->actingAs($this->admin)->get('/admin/premium/orders')
            ->assertOk()
            ->assertSee('1.99 €')
            ->assertDontSee('9.98 €')
            ->assertSee('500.00 XGP')
            ->assertSee('Own token')
            ->assertDontSee('0.05 WAX');
    }

    public function testWithoutOrdersTheListSaysSo(): void
    {
        $this->actingAs($this->admin)->get('/admin/premium/orders')
            ->assertOk()
            ->assertSee('No orders yet.');
    }

    public function testTheAdminSetsThePremiumMode(): void
    {
        $this->actingAs($this->admin)->get('/admin/premium')
            ->assertOk()
            ->assertSee('name="premium_mode"', false)
            ->assertSee('closed (Play2Win)');

        $this->actingAs($this->admin)->post('/admin/premium', ['premium_mode' => 'pay2win'])->assertRedirect(route('admin.premium'));
        $this->assertSame('pay2win', DB::table('options')->where('name', 'premium_mode')->value('value'));

        $this->actingAs($this->admin)->post('/admin/premium', ['premium_mode' => 'free4all']);
        $this->assertSame('pay2win', DB::table('options')->where('name', 'premium_mode')->value('value'));

        app()->forgetScopedInstances();
        $this->assertSame('pay2win', app(SettingsService::class)->getString('premium_mode'));
    }

    public function testTheAdminChoosesTheWaysToPay(): void
    {
        config(['premium.wax.account' => 'xgpshop', 'premium.stripe.secret' => '', 'premium.stripe.webhook_secret' => '']);

        $this->actingAs($this->admin)->get('/admin/premium')
            ->assertOk()
            ->assertSee('name="payment_methods[]"', false)
            ->assertSee('STRIPE_SECRET / STRIPE_WEBHOOK_SECRET missing in .env')
            ->assertSee('set up');

        $this->actingAs($this->admin)->post('/admin/premium', ['payment_methods_form' => '1', 'payment_methods' => ['wax', 'stripe']]);
        $this->assertSame('stripe,wax', DB::table('options')->where('name', 'payment_methods')->value('value'));

        // all boxes unticked: none
        $this->actingAs($this->admin)->post('/admin/premium', ['payment_methods_form' => '1']);
        $this->assertSame('', DB::table('options')->where('name', 'payment_methods')->value('value'));

        $this->actingAs($this->admin)->post('/admin/premium', ['payment_methods_form' => '1', 'payment_methods' => ['paypal']]);
        $this->assertSame('', DB::table('options')->where('name', 'payment_methods')->value('value'));

        // another form of the page leaves them alone
        DB::table('options')->where('name', 'payment_methods')->update(['value' => 'wax']);
        $this->actingAs($this->admin)->post('/admin/premium', ['registration_dark_matter' => '100']);
        $this->assertSame('wax', DB::table('options')->where('name', 'payment_methods')->value('value'));
    }

    public function testTheWaxTransfersAreListed(): void
    {
        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        DB::connection('central')->table('wax_transfers')->insert([
            ['sequence' => 1, 'trx_id' => str_repeat('a', 64), 'block_num' => 10, 'block_time' => time(), 'from_account' => 'player.wam', 'units' => 46_676_000_000,
                'memo' => 'xgp:1', 'universe' => null, 'order_id' => 1, 'result' => 'credited', 'checked_at' => time()],
            ['sequence' => 2, 'trx_id' => str_repeat('b', 64), 'block_num' => 11, 'block_time' => time(), 'from_account' => 'other.wam', 'units' => 150_000_000,
                'memo' => 'thanks', 'universe' => null, 'order_id' => null, 'result' => 'unknown', 'checked_at' => time()],
        ]);

        $this->actingAs($this->admin)->get('/admin/premium/orders')
            ->assertOk()
            ->assertSee('WAX transfers')
            ->assertSee('466.76 WAX')
            ->assertSee('other.wam')
            ->assertSee('unknown memo')
            ->assertSee('credited');
    }

    public function testTheBooksGetEveryOrderThatMovedMoneyAsCsv(): void
    {
        $this->order($this->admin->id, 'dm_10k', 199, 'paid');
        $this->order($this->admin->id, 'dm_50k', 799, 'pending');
        $this->order(999, 'dm_300k', 3499, 'refunded');
        DB::table('dark_matter_orders')->where('package', 'dm_10k')->update(['tax' => 32, 'country' => 'DE', 'provider_payment' => 'pi_1', 'paid_at' => 1_700_000_000]);

        $response = $this->actingAs($this->admin)->get('/admin/premium/orders.csv');

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=orders-', (string) $response->headers->get('Content-Disposition'));
        $lines = explode("\n", trim($response->streamedContent()));
        $this->assertCount(3, $lines);
        $this->assertSame('order,status,created_at,paid_at,refunded_at,user_id,package,dark_matter,amount,currency,tax,country,provider,provider_session,provider_payment,provider_amount,provider_currency,withdrawal_waived_at', $lines[0]);
        $this->assertStringContainsString(',paid,', $lines[1]);
        $this->assertStringContainsString('"2023-11-14 22:13:20"', $lines[1]);
        $this->assertStringContainsString(',dm_10k,1000,1.99,EUR,0.32,DE,stripe,,pi_1,,,', $lines[1]);
        $this->assertStringContainsString(',refunded,', $lines[2]);
        $this->assertStringContainsString(',999,dm_300k,1000,34.99,EUR,,,stripe,', $lines[2]);
        // the open order moved no money
        $this->assertStringNotContainsString('dm_50k', $response->streamedContent());

        $this->get('/admin/premium/orders')->assertOk()->assertSee('/admin/premium/orders.csv', false);
    }

    public function testWithoutTheTransferTableTheOrdersStillShow(): void
    {
        $this->actingAs($this->admin)->get('/admin/premium/orders')
            ->assertOk()
            ->assertSee('No WAX transfers yet.');
    }

    private function order(int $userId, string $package, int $amount, string $status): void
    {
        DB::table('dark_matter_orders')->insert([
            'user_id' => $userId, 'package' => $package, 'dark_matter' => 1_000, 'amount' => $amount, 'currency' => 'eur',
            'provider' => 'stripe', 'status' => $status, 'created_at' => time(), 'paid_at' => $status === 'pending' ? null : time(),
        ]);
    }
}
