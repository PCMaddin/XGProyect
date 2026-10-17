<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Home;

use App\Http\Controllers\Home\RegisterController;
use App\Models\Options;
use App\Models\User;
use App\Services\RegistrationService;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * A mail server that cannot be reached must not end a registration with an
 * error page: the account exists at that point. The player is logged in and
 * the failure is logged.
 */
#[CoversClass(RegisterController::class)]
class RegisterMailFailureTest extends DatabaseTestCase
{
    public function testTheRegistrationGoesOnWhenTheWelcomeMailFails(): void
    {
        config([
            'INSTALLED' => 'true',
            'SECRETWORD' => 'test-secret',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);

        foreach (['lang' => 'en', 'reg_enable' => '1', 'reg_welcome_message' => '0', 'reg_welcome_email' => '1'] as $name => $value) {
            Options::insert(['name' => $name, 'value' => $value, 'type' => 'int']);
        }

        $registration = $this->createMock(RegistrationService::class);
        $registration->expects($this->once())->method('register')->willReturnCallback(function (array $data): User {
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'home_planet_id' => 1, 'current_planet' => 1]);
            $user->save();

            return $user;
        });
        $this->app->instance(RegistrationService::class, $registration);

        $response = $this->post('/register', [
            'username' => 'Newbie',
            'password' => 'Geheim12345',
            'email' => 'newbie@example.com',
            'agb' => 'on',
        ]);

        $response->assertRedirect('game.php?page=overview');
        $this->assertAuthenticated();
    }
}
