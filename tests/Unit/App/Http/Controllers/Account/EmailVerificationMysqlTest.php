<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Account;

use App\Http\Controllers\Account\VerifyEmailController;
use App\Libraries\Users;
use App\Mail\VerifyEmail;
use App\Mail\Welcome;
use App\Models\User;
use App\Services\Account\EmailVerification;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use App\View\Components\Game\VerifyEmail as VerifyEmailBanner;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Tests\MysqlTestCase;

/**
 * A player who registered on the start page confirms the email address
 * with the link from the mail; until then every game page reminds them, the
 * shop sells nothing, and after a week the account is deleted. A link for
 * another address or a forged one confirms nothing. Players the admin
 * created, bots and admins need no link, and without the option everyone
 * counts as confirmed.
 */
#[CoversClass(EmailVerification::class)]
#[CoversClass(VerifyEmailController::class)]
#[CoversClass(VerifyEmailBanner::class)]
class EmailVerificationMysqlTest extends MysqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);
        Mail::fake();
    }

    public function testANewPlayerConfirmsTheAddressWithTheLinkFromTheMail(): void
    {
        $this->post('/register', ['username' => 'Newbie', 'email' => 'newbie@example.com', 'password' => 'secret-password', 'agb' => 'on'])
            ->assertRedirect('game.php?page=overview');

        $user = User::where('email', 'newbie@example.com')->sole();
        $this->assertNull($user->email_verified_at);
        Mail::assertNotSent(Welcome::class);
        $link = '';
        Mail::assertSent(VerifyEmail::class, function (VerifyEmail $mail) use (&$link): bool {
            $link = $mail->link;

            return $mail->hasTo('newbie@example.com');
        });

        $this->asPlayer($user)->get('/game.php?page=overview')->assertOk()->assertSee('id="verify-email"', false)->assertSee('newbie@example.com')
            // every game page leads to "report a problem", from this page
            ->assertSee('/account/report?from=%2Fgame.php%3Fpage%3Doverview', false);

        // the link works without signing in, on any device
        $this->app['auth']->forgetGuards();
        $this->get($link)->assertOk()->assertSee(__('account/verify.vf_done_title'));
        $this->assertNotNull($user->refresh()->email_verified_at);

        $this->asPlayer($user)->get('/game.php?page=overview')->assertOk()->assertDontSee('id="verify-email"', false);
    }

    public function testAForgedLinkOrOneForAnotherAddressConfirmsNothing(): void
    {
        $user = $this->unconfirmed('Newbie', 'newbie@example.com');
        $link = app(EmailVerification::class)->link($user);

        $this->get(str_replace('/verify/' . $user->id . '/', '/verify/' . ($user->id + 1) . '/', $link))->assertForbidden();
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature=' . str_repeat('0', 64), $link) ?? '')->assertForbidden();

        // the address changed after the mail went out
        DB::table('users')->where('id', $user->id)->update(['email' => 'other@example.com']);
        $this->get($link)->assertForbidden();

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function testTheShopSellsNothingBeforeTheConfirmation(): void
    {
        config(['premium.stripe.secret' => 'sk_test_key', 'premium.stripe.webhook_secret' => 'whsec_test']);
        app(SettingsService::class)->write('premium_mode', 'pay2win');
        Http::fake();
        $user = $this->unconfirmed('Buyer', 'buyer@example.com');

        $this->asPlayer($user)->post('/game.php?page=darkmatter', ['package' => 'dm_10k', 'withdrawal_waiver' => '1'])
            ->assertOk()->assertSee(__('game/darkmatter.dm_verify_email_first'));

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('dark_matter_orders')->count());
    }

    public function testThePlayerAsksForTheLinkAgain(): void
    {
        $user = $this->unconfirmed('Newbie', 'newbie@example.com');

        $this->asPlayer($user)->from('/game.php?page=overview')->post('/account/verify/resend')
            ->assertRedirect('/game.php?page=overview')->assertSessionHas('verify_email_status', __('account/verify.vf_resent'));

        Mail::assertSent(VerifyEmail::class, 1);
    }

    public function testUnconfirmedAccountsGoAfterAWeekAndNobodyElse(): void
    {
        $old = time() - 8 * 86400;
        $gone = $this->unconfirmed('Gone', 'gone@example.com', $old);
        $fresh = $this->unconfirmed('Fresh', 'fresh@example.com');
        $confirmed = $this->unconfirmed('Confirmed', 'confirmed@example.com', $old);
        app(EmailVerification::class)->confirm($confirmed);
        $admin = $this->unconfirmed('Admin', 'admin@example.com', $old);
        DB::table('users')->where('id', $admin->id)->update(['authlevel' => 3]);
        $bot = $this->unconfirmed('Bot', 'bot@example.com', $old);
        DB::table('users')->where('id', $bot->id)->update(['user_is_bot' => 1]);

        $this->assertSame(1, app(EmailVerification::class)->pruneUnconfirmed(time()));

        $this->assertNull(DB::table('users')->find($gone->id));
        $this->assertSame(0, DB::table('planets')->where('planet_user_id', $gone->id)->count());
        foreach ([$fresh, $confirmed, $admin, $bot] as $kept) {
            $this->assertNotNull(DB::table('users')->find($kept->id), $kept->name);
        }
    }

    public function testWithoutTheOptionEveryoneCountsAsConfirmed(): void
    {
        app(SettingsService::class)->write('reg_verify_email', 0);

        $this->post('/register', ['username' => 'Newbie', 'email' => 'newbie@example.com', 'password' => 'secret-password', 'agb' => 'on']);

        $user = User::where('email', 'newbie@example.com')->sole();
        $this->assertNotNull($user->email_verified_at);
        Mail::assertNotSent(VerifyEmail::class);
        Mail::assertSent(Welcome::class);

        // switched on later, nobody who registered meanwhile is deleted
        app(SettingsService::class)->write('reg_verify_email', 1);
        DB::table('users')->update(['register_time' => time() - 30 * 86400]);
        $this->assertSame(0, app(EmailVerification::class)->pruneUnconfirmed(time()));
    }

    public function testAFinishedPasswordResetConfirmsTheAddress(): void
    {
        $user = $this->unconfirmed('Newbie', 'newbie@example.com');
        $token = Password::broker()->createToken($user);

        $this->post('/account/reset', ['token' => $token, 'email' => 'newbie@example.com', 'password' => 'new-secret-password', 'password_confirmation' => 'new-secret-password'])
            ->assertRedirect(route('account.recover'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function testTheMailHoldsTheSignedLinkForThisUniverse(): void
    {
        $user = $this->unconfirmed('Newbie', 'newbie@example.com');
        $link = app(EmailVerification::class)->link($user);

        $this->assertStringContainsString('/account/verify/' . $user->id . '/' . EmailVerification::hash('newbie@example.com'), $link);
        $this->assertTrue(URL::hasValidSignature(\Illuminate\Http\Request::create($link)));

        $html = (new VerifyEmail('Newbie', $link, time() + 7 * 86400))->locale('de')->render();
        $this->assertStringContainsString(htmlspecialchars($link, ENT_QUOTES), $html);
        $this->assertStringContainsString(__('account/verify.vf_mail_button', [], 'de'), $html);
    }

    private function unconfirmed(string $name, string $email, ?int $registered = null): User
    {
        $user = app(RegistrationService::class)->register(['name' => $name, 'email' => $email, 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $user);

        if ($registered !== null) {
            DB::table('users')->where('id', $user->id)->update(['register_time' => $registered]);
        }

        // as the registration returns it, without what the database fills in
        return $user;
    }

    private function asPlayer(User $user): static
    {
        (new ReflectionProperty(Users::class, 'instance'))->setValue(null, null);
        $password = User::findOrFail($user->id)->getAuthPassword();

        return $this->actingAs($user)->withSession([
            'user_id' => $user->id,
            'user_password' => Hash::make($password . '-test-secret'),
        ]);
    }
}
