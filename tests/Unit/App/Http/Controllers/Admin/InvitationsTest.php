<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\RegistrationController;
use App\Models\User;
use App\Services\Account\Invitations;
use App\Services\SettingsService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The admin switches registration to invitations only, sets the most
 * players the universe takes, and makes and deletes invitation codes; the
 * page lists each code with its link.
 */
#[CoversClass(RegistrationController::class)]
#[CoversClass(Invitations::class)]
class InvitationsTest extends DatabaseTestCase
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

    public function testTheAdminSetsInvitationsOnlyAndTheLimit(): void
    {
        $this->actingAs($this->admin)->post('/admin/registration', ['reg_enable' => 'on', 'reg_invite_only' => 'on', 'reg_max_players' => '250'])
            ->assertRedirect(route('admin.registration'));

        $settings = app(SettingsService::class);
        $this->assertTrue($settings->getBool('reg_invite_only'));
        $this->assertSame(250, $settings->getInt('reg_max_players'));

        $this->actingAs($this->admin)->post('/admin/registration', ['reg_enable' => 'on', 'reg_max_players' => '-5']);
        $this->assertSame(250, $settings->getInt('reg_max_players'), 'refused, unchanged');
    }

    public function testTheAdminMakesListsAndDeletesCodes(): void
    {
        $this->actingAs($this->admin)->post('/admin/registration/invitations', ['uses' => '20', 'days' => '14', 'note' => 'Discord'])
            ->assertRedirect(route('admin.registration'))->assertSessionHas('success');

        $invitation = DB::table('invitations')->sole();
        $this->assertSame(20, (int) $invitation->uses);
        $this->assertSame('Discord', $invitation->note);
        $this->assertEqualsWithDelta(time() + 14 * 86400, (int) $invitation->expires_at, 5);
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{10}$/', $invitation->code);

        $this->actingAs($this->admin)->get('/admin/registration')->assertOk()
            ->assertSee($invitation->code)->assertSee('?invite=' . $invitation->code, false)->assertSee('0 / 20');

        $this->actingAs($this->admin)->delete('/admin/registration/invitations/' . $invitation->id)->assertRedirect(route('admin.registration'));
        $this->assertSame(0, DB::table('invitations')->count());

        $this->actingAs($this->admin)->post('/admin/registration/invitations', ['uses' => '0'])->assertSessionHasErrors('uses');
    }
}
