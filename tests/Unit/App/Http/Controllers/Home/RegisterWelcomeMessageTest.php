<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Home;

use App\Http\Controllers\Home\RegisterController;
use App\Models\Messages;
use App\Models\Options;
use App\Models\User;
use App\Services\RegistrationService;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The welcome message linked to tutorial, chat, forum and support pages the
 * game does not have. It now links the forum the admin set, or nothing.
 */
#[CoversClass(RegisterController::class)]
class RegisterWelcomeMessageTest extends DatabaseTestCase
{
    public function testTheMessageLinksTheConfiguredForum(): void
    {
        $text = $this->welcomeMessageFor('https://forum.example.org/');

        $this->assertStringContainsString('<a href="https://forum.example.org/" target="_blank" rel="noopener">forum</a>', $text);
        $this->assertStringNotContainsString('game.php?page=', $text);
        $this->assertStringContainsString('deleted after one week', $text);
    }

    public function testWithoutAForumTheMessageHasNoLink(): void
    {
        $text = $this->welcomeMessageFor('');

        $this->assertStringNotContainsString('<a ', $text);
        $this->assertStringContainsString('Greetings, emperor New &lt;b&gt;bie!', $text);
    }

    private function welcomeMessageFor(string $forumUrl): string
    {
        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret']);

        foreach (['lang' => 'en', 'reg_enable' => '1', 'reg_welcome_message' => '1', 'reg_welcome_email' => '0', 'forum_url' => $forumUrl] as $name => $value) {
            Options::insert(['name' => $name, 'value' => $value, 'type' => 'string']);
        }

        $registration = $this->createMock(RegistrationService::class);
        $registration->method('register')->willReturnCallback(function (array $data): User {
            // the name rules do not allow markup, the escaping still must hold
            $user = new User(['name' => 'New <b>bie', 'email' => $data['email'], 'password' => $data['password'], 'home_planet_id' => 1, 'current_planet' => 1]);
            $user->save();

            return $user;
        });
        $this->app->instance(RegistrationService::class, $registration);

        $this->post('/register', [
            'username' => 'Newbie',
            'password' => 'Geheim12345',
            'email' => 'newbie@example.com',
            'agb' => 'on',
        ]);

        $text = Messages::query()->value('message_text');
        $this->assertIsString($text);

        return $text;
    }
}
