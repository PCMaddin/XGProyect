<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Home;

use App\Http\Controllers\Home\RegisterController;
use App\Services\Account\Invitations;
use App\Services\Account\RegistrationGate;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * An alpha universe takes players only with an invitation code, and only
 * up to a number of players. A code is good for as many registrations as
 * it was made for, until it expires; a registration that fails gives it
 * back. Bots do not count towards the limit.
 */
#[CoversClass(RegisterController::class)]
#[CoversClass(RegistrationGate::class)]
#[CoversClass(Invitations::class)]
class RegistrationGateMysqlTest extends MysqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);
        Mail::fake();
    }

    public function testWithInvitationsOnlyAValidCodeRegisters(): void
    {
        app(SettingsService::class)->write('reg_invite_only', 1);
        $code = app(Invitations::class)->create(1, null, 'alpha tester', 'admin', time());

        $this->get('/')->assertOk()->assertSee('id="invite"', false);

        $this->register('First', ['invite' => ''])->assertSessionHasErrors(['invite' => __('home/register.re_invite_invalid')], null, 'register');
        $this->register('First', ['invite' => 'WRONGCODE1'])->assertSessionHasErrors('invite', null, 'register');
        $this->assertSame(0, DB::table('users')->count());

        // typed as read aloud: lower case, with a dash
        $this->register('First', ['invite' => strtolower(substr($code, 0, 5) . '-' . substr($code, 5))])->assertRedirect('game.php?page=overview');
        $this->assertSame(1, DB::table('invitations')->value('used'));

        // used up
        $this->app['auth']->forgetGuards();
        $this->register('Second', ['invite' => $code])->assertSessionHasErrors('invite', null, 'register');
        $this->assertSame(1, DB::table('users')->count());
    }

    public function testAnExpiredCodeRegistersNobody(): void
    {
        app(SettingsService::class)->write('reg_invite_only', 1);
        $code = app(Invitations::class)->create(5, time() - 1, '', 'admin', time() - 3600);

        $this->register('Late', ['invite' => $code])->assertSessionHasErrors('invite', null, 'register');
        $this->assertSame(0, DB::table('users')->count());
    }

    public function testAnInvitationLinkFillsInTheCode(): void
    {
        app(SettingsService::class)->write('reg_invite_only', 1);

        $this->get('/?invite=abcde-fghjk')->assertOk()->assertSee('value="ABCDEFGHJK"', false);
    }

    public function testAFullUniverseTakesNoMorePlayersButBotsDoNotCount(): void
    {
        app(SettingsService::class)->write('reg_max_players', 2);
        app(RegistrationService::class)->register(['name' => 'Bot', 'email' => 'bot@example.com', 'password' => 'secret-password']);
        DB::table('users')->update(['user_is_bot' => 1]);

        $this->register('Player')->assertRedirect('game.php?page=overview');
        $this->app['auth']->forgetGuards();
        $this->register('Second')->assertRedirect('game.php?page=overview');
        $this->app['auth']->forgetGuards();

        $this->get('/')->assertSee('id="registration-full"', false);
        $this->register('Third')->assertSessionHasErrors(['username' => __('home/register.re_universe_closed_full')], null, 'register');
        $this->assertSame(3, DB::table('users')->count());
    }

    public function testACodeTakenForAFailedRegistrationCountsAgain(): void
    {
        app(SettingsService::class)->write('reg_invite_only', 1);
        $code = app(Invitations::class)->create(1, null, '', 'admin', time());
        $gate = app(RegistrationGate::class);

        $this->assertNull($gate->admit($code, time()));
        $this->assertSame(1, DB::table('invitations')->value('used'));
        $gate->release();
        $this->assertSame(0, DB::table('invitations')->value('used'));

        // two registrations racing for the last use: one gets it
        $this->assertTrue(app(Invitations::class)->redeem($code, time()));
        $this->assertFalse(app(Invitations::class)->redeem($code, time()));
    }

    /**
     * @param array<string, string> $extra
     *
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function register(string $name, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->from('/')->post('/register', [
            'username' => $name,
            'email' => strtolower($name) . '@example.com',
            'password' => 'secret-password',
            'agb' => 'on',
        ] + $extra);
    }
}
