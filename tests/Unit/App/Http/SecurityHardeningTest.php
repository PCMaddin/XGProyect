<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http;

use App\Http\Controllers\Account\RecoverController;
use App\Http\Middleware\SecurityHeaders;
use App\Mail\Recover;
use App\Models\User;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Accounts hold dark matter bought with money: passwords cannot be guessed
 * at speed, a reset link never points to a host the request named, and no
 * other site can frame the game to make a player click.
 */
#[CoversClass(SecurityHeaders::class)]
#[CoversClass(RecoverController::class)]
class SecurityHardeningTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'app.url' => 'https://play.example.org', 'app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        User::create(['name' => 'Player', 'email' => 'player@example.com', 'password' => 'secret-password', 'home_planet_id' => 1, 'current_planet' => 1]);
    }

    public function testPasswordsCannotBeGuessedAtSpeed(): void
    {
        for ($try = 1; $try <= 5; $try++) {
            $this->post('/login', ['username' => 'player@example.com', 'password' => 'guess-' . $try])->assertRedirect();
        }

        $this->post('/login', ['username' => 'player@example.com', 'password' => 'guess-6'])->assertStatus(429);
        // the admin sign-in counts the same account
        $this->post('/admin/login', ['inputEmail' => 'player@example.com', 'inputPassword' => 'guess-7'])->assertStatus(429);
        // another account from the same address still may
        $this->post('/login', ['username' => 'other@example.com', 'password' => 'guess'])->assertRedirect();
    }

    public function testAccountsAndResetMailsAreLimitedPerAddress(): void
    {
        Mail::fake();

        for ($try = 1; $try <= 5; $try++) {
            $this->post('/account/recover', ['email' => 'player@example.com'])->assertRedirect();
        }

        $this->post('/account/recover', ['email' => 'player@example.com'])->assertStatus(429);

        for ($try = 1; $try <= 5; $try++) {
            $this->post('/register', ['name' => 'New' . $try, 'email' => 'new' . $try . '@example.com', 'password' => 'secret-password', 'agb' => 'on']);
        }

        $this->post('/register', ['name' => 'New6', 'email' => 'new6@example.com', 'password' => 'secret-password', 'agb' => 'on'])->assertStatus(429);
    }

    public function testTheResetLinkIsOnTheGamesAddressWhateverTheHostHeaderSays(): void
    {
        Mail::fake();

        $this->withServerVariables(['HTTP_HOST' => 'attacker.example'])->post('/account/recover', ['email' => 'player@example.com'])->assertRedirect();

        Mail::assertSent(Recover::class, static fn (Recover $mail): bool => str_starts_with($mail->resetUrl, 'https://play.example.org/account/reset/') &&
            !str_contains($mail->resetUrl, 'attacker.example'));
    }

    public function testNoOtherSiteCanFrameTheGame(): void
    {
        $this->get('/')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://play.example.org/')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        // the operators' area keeps its stricter header
        $this->get('/operator')->assertHeader('X-Frame-Options', 'DENY');
    }
}
