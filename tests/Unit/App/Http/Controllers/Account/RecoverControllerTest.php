<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Account;

use App\Http\Controllers\Account\RecoverController;
use App\Http\Requests\RecoverRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Mail\Recover;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Regression: asking for a new password replaced the password right away
 * with a random one mailed in plain text, so anybody knowing a player's
 * email address could lock them out; the answer also told whether an
 * account existed for an address.
 */
#[CoversClass(RecoverController::class)]
class RecoverControllerTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // the reset tokens are signed with the application key
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
    }

    public function testAskingSendsALinkAndKeepsThePassword(): void
    {
        Mail::fake();
        $user = $this->seedUser();

        $this->controller()->recover($this->request(RecoverRequest::class, ['email' => 'player@example.com']));

        Mail::assertSent(Recover::class, fn (Recover $mail): bool => str_contains($mail->resetUrl, '/account/reset/'));
        $this->assertTrue(Hash::check('old-secret', $this->storedPassword($user)));
    }

    public function testUnknownAddressesGetTheSameAnswer(): void
    {
        Mail::fake();
        $this->seedUser();

        $known = $this->controller()->recover($this->request(RecoverRequest::class, ['email' => 'player@example.com']));
        $unknown = $this->controller()->recover($this->request(RecoverRequest::class, ['email' => 'nobody@example.com']));

        Mail::assertSentCount(1);
        $this->assertSame(session('message'), __('account/recover.re_sent'));
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
    }

    public function testTheLinkSetsTheNewPassword(): void
    {
        $user = $this->seedUser();
        $token = $this->tokenFor($user);

        $this->controller()->reset($this->request(ResetPasswordRequest::class, [
            'token' => $token,
            'email' => 'player@example.com',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ]));

        $this->assertTrue(Hash::check('new-secret', $this->storedPassword($user)));
    }

    public function testAWrongTokenChangesNothing(): void
    {
        $user = $this->seedUser();
        $this->tokenFor($user);

        $this->controller()->reset($this->request(ResetPasswordRequest::class, [
            'token' => 'guessed',
            'email' => 'player@example.com',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ]));

        $this->assertTrue(Hash::check('old-secret', $this->storedPassword($user)));
    }

    public function testTheMailCarriesTheLink(): void
    {
        $settings = $this->createStub(SettingsService::class);
        $settings->method('getString')->willReturn('XGP');
        $this->app->instance(SettingsService::class, $settings);

        $html = (new Recover('https://xgp.test/account/reset/abc?email=player%40example.com'))->render();

        $this->assertStringContainsString('https://xgp.test/account/reset/abc?email=player%40example.com', $html);
        $this->assertStringContainsString('60', $html);
    }

    private function tokenFor(User $user): string
    {
        $broker = Password::broker();
        $this->assertInstanceOf(PasswordBroker::class, $broker);

        return $broker->createToken($user);
    }

    private function controller(): RecoverController
    {
        return app(RecoverController::class);
    }

    /**
     * @template T of FormRequest
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $data
     *
     * @return T
     */
    private function request(string $class, array $data): FormRequest
    {
        $request = $class::create('/', 'POST', $data);
        $request->setLaravelSession(app('session.store'));
        $request->setValidator(Validator::make($data, array_fill_keys(array_keys($data), 'nullable')));

        return $request;
    }

    private function seedUser(): User
    {
        $user = new User(['name' => 'Player', 'email' => 'player@example.com', 'password' => 'old-secret', 'home_planet_id' => 1, 'current_planet' => 1]);
        $user->save();

        return $user;
    }

    private function storedPassword(User $user): string
    {
        $hash = DB::table('users')->where('id', $user->id)->value('password');
        $this->assertIsString($hash);

        return $hash;
    }
}
