<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Account;

use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Account\TwoFactorChallengeController;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\User;
use App\Services\Auth\LoginFlow;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Two-factor sign-in: set up with the authenticator app, then every
 * sign-in (game and admin) waits signed out for the code; a code counts
 * once, recovery codes help when the phone is gone, guessing is limited,
 * and the admin area requires it.
 */
#[CoversClass(TwoFactor::class)]
#[CoversClass(LoginFlow::class)]
#[CoversClass(TwoFactorChallengeController::class)]
#[CoversClass(SecurityController::class)]
#[CoversClass(RequireTwoFactor::class)]
class TwoFactorTest extends DatabaseTestCase
{
    private const PASSWORD = 'secret-password';

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
    }

    public function testAPlayerSwitchesItOnWithTheAppsCode(): void
    {
        $player = $this->user('player@example.com');

        $this->actingAs($player)->get('/account/security')->assertOk()->assertSee('Two-factor sign-in')->assertSee('off');
        $this->actingAs($player)->post('/account/security/start')->assertRedirect(route('account.security'));

        $secret = session('two_factor_setup');
        $this->assertIsString($secret);
        $this->actingAs($player)->get('/account/security')->assertOk()->assertSee('<svg', false)->assertSee(substr($secret, 0, 4));

        $this->actingAs($player)->post('/account/security/confirm', ['code' => '000000'])->assertSessionHas('error');
        $this->assertFalse($this->isOn($player));

        $this->actingAs($player)->post('/account/security/confirm', ['code' => $this->code($secret)])
            ->assertRedirect(route('account.security'))->assertSessionHas('recovery_codes');
        $codes = session('recovery_codes');
        $this->assertIsArray($codes);
        $this->assertCount(8, $codes);

        $fresh = User::findOrFail($player->id);
        $this->assertTrue($fresh->hasTwoFactor());
        // the secret is stored encrypted
        $this->assertNotSame($secret, DB::table('users')->where('id', $player->id)->value('two_factor_secret'));
        $this->assertSame($secret, $fresh->two_factor_secret);
    }

    public function testTheGameSignInWaitsForTheCode(): void
    {
        $player = $this->user('player@example.com');
        [$secret] = $this->enable($player);

        $this->post('/login', ['username' => 'player@example.com', 'password' => self::PASSWORD])->assertRedirect(route('account.two-factor'));
        $this->assertGuest();
        $this->get('/account/two-factor')->assertOk()->assertSee('Confirm sign-in');

        $this->post('/account/two-factor', ['code' => '123456'])->assertSessionHas('error');
        $this->assertGuest();

        $code = $this->code($secret, 1);
        $this->post('/account/two-factor', ['code' => $code])->assertRedirect('game.php?page=overview');
        $this->assertAuthenticatedAs($player);
        $this->assertSame($player->id, session('user_id'));

        // the same code does not work twice
        Auth::logout();
        $this->post('/login', ['username' => 'player@example.com', 'password' => self::PASSWORD]);
        $this->post('/account/two-factor', ['code' => $code])->assertSessionHas('error');
        $this->assertGuest();
    }

    public function testARecoveryCodeWorksOnce(): void
    {
        $player = $this->user('player@example.com');
        [, $codes] = $this->enable($player);

        $this->post('/login', ['username' => 'player@example.com', 'password' => self::PASSWORD]);
        $this->post('/account/two-factor', ['code' => strtoupper($codes[3])])->assertRedirect('game.php?page=overview');
        $this->assertAuthenticatedAs($player);
        $this->assertSame(7, app(TwoFactor::class)->recoveryCodesLeft(User::findOrFail($player->id)));

        Auth::logout();
        $this->post('/login', ['username' => 'player@example.com', 'password' => self::PASSWORD]);
        $this->post('/account/two-factor', ['code' => $codes[3]])->assertSessionHas('error');
        $this->assertGuest();
    }

    public function testGuessingIsLimitedAndTheCodeMustFollowSoon(): void
    {
        $player = $this->user('player@example.com');
        [$secret] = $this->enable($player);

        $this->post('/login', ['username' => 'player@example.com', 'password' => self::PASSWORD]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/account/two-factor', ['code' => '000000']);
        }

        // even the right code waits now
        $this->post('/account/two-factor', ['code' => $this->code($secret)])
            ->assertSessionHas('error', static fn (string $message): bool => str_contains($message, 'Too many attempts'));
        $this->assertGuest();

        // without a password a moment before, there is nothing to confirm
        $this->travel(6)->minutes();
        $this->get('/account/two-factor')->assertRedirect(route('home.index'));
        $this->post('/account/two-factor', ['code' => $this->code($secret)])->assertRedirect(route('home.index'));
        $this->assertGuest();
    }

    public function testThePlayerSwitchesItOffWithPasswordAndCode(): void
    {
        $player = $this->user('player@example.com');
        [$secret] = $this->enable($player);

        $this->actingAs($player)->post('/account/security/disable', ['password' => 'wrong', 'code' => $this->code($secret)])->assertSessionHas('error');
        $this->assertTrue($this->isOn($player));

        $this->actingAs($player)->post('/account/security/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret, 1)])->assertSessionHas('success');
        $this->assertFalse($this->isOn($player));
    }

    public function testNewRecoveryCodesReplaceTheOld(): void
    {
        $player = $this->user('player@example.com');
        [, $old] = $this->enable($player);

        $this->actingAs($player)->post('/account/security/codes', ['password' => self::PASSWORD])->assertSessionHas('recovery_codes');
        $new = session('recovery_codes');
        $this->assertIsArray($new);
        $this->assertIsString($new[0]);

        $twoFactor = app(TwoFactor::class);
        $fresh = User::findOrFail($player->id);
        $this->assertFalse($twoFactor->check($fresh, $old[0], time()));
        $this->assertTrue($twoFactor->check($fresh, $new[0], time()));
    }

    public function testTheAdminAreaRequiresIt(): void
    {
        config(['auth.two_factor.admin_required' => true]);
        $admin = $this->user('admin@example.com', 3);

        $this->actingAs($admin)->get('/admin/premium')->assertRedirect(route('account.security'));
        $this->actingAs($admin)->get('/account/security')->assertSee('The admin area requires two-factor sign-in.');

        [$secret] = $this->enable($admin);
        $this->actingAs($admin)->get('/admin/premium')->assertOk();

        // an admin keeps it while the admin area requires it
        $this->actingAs($admin)->post('/account/security/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret)])->assertSessionHas('error');
        $this->assertTrue($this->isOn($admin));

        // the admin sign-in waits for the code too and goes on to the admin area
        Auth::logout();
        $this->post('/admin/login', ['inputEmail' => 'admin@example.com', 'inputPassword' => self::PASSWORD])->assertRedirect(route('account.two-factor'));
        $this->assertGuest();
        $this->post('/account/two-factor', ['code' => $this->code($secret, 1)])->assertRedirect('admin/home');
        $this->assertAuthenticatedAs($admin);
    }

    public function testWithoutItTheSignInIsUnchanged(): void
    {
        $this->user('player@example.com');

        $this->post('/login', ['username' => 'player@example.com', 'password' => self::PASSWORD])->assertRedirect('game.php?page=overview');
        $this->assertAuthenticated();
    }

    private function isOn(User $user): bool
    {
        return User::findOrFail($user->id)->hasTwoFactor();
    }

    private function user(string $email, int $authlevel = 0): User
    {
        $user = new User(['name' => explode('@', $email)[0], 'email' => $email, 'password' => self::PASSWORD, 'home_planet_id' => 1, 'current_planet' => 1]);
        $user->authlevel = $authlevel;
        $user->save();

        return $user;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function enable(User $user): array
    {
        $twoFactor = app(TwoFactor::class);
        $secret = $twoFactor->newSecret();
        // a step back, so the codes of the test's own steps stay unused
        $codes = $twoFactor->enable($user, $secret, $this->code($secret, -1), time());
        $this->assertNotNull($codes);

        return [$secret, $codes];
    }

    private function code(string $secret, int $offset = 0): string
    {
        $totp = new Totp();

        return $totp->code($secret, $totp->step(time()) + $offset);
    }
}
