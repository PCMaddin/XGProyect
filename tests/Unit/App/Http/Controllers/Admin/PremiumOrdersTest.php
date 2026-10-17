<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\PremiumController;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
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

    private function order(int $userId, string $package, int $amount, string $status): void
    {
        DB::table('dark_matter_orders')->insert([
            'user_id' => $userId, 'package' => $package, 'dark_matter' => 1_000, 'amount' => $amount, 'currency' => 'eur',
            'provider' => 'stripe', 'status' => $status, 'created_at' => time(), 'paid_at' => $status === 'pending' ? null : time(),
        ]);
    }
}
