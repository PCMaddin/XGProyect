<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Models\Preferences;
use App\Models\User;
use App\Services\Game\AccountUpdateService;
use App\Services\Game\PreferencesService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AccountUpdateService::class)]
class AccountUpdateServiceTest extends TestCase
{
    /**
     * Regression: the preferences page checked only the length of a new name,
     * so it let through the markup names the nickname page rejects.
     */
    public function testANameWithMarkupIsRejected(): void
    {
        $user = new User();
        $user->id = 1;
        $user->name = 'Player';
        $user->password = 'secret';
        $request = Request::create('/', 'POST', [
            'new_user_name' => '<svg/onload=x>',
            'confirmation_user_password' => 'secret',
        ]);

        $error = $this->service()->save($request, $user, new Preferences(['preference_nickname_change' => 0]), []);

        $this->assertSame(strtr($this->translation('game/preferences.pr_error_user_invalid_characters'), ['%s' => '<svg/onload=x>']), $error);
        $this->assertSame('Player', $user->name);
    }

    public function testAWrongPasswordIsRejected(): void
    {
        $user = new User();
        $user->password = 'secret';
        $request = Request::create('/', 'POST', [
            'new_user_password' => 'new-secret',
            'current_user_password' => 'wrong',
        ]);

        $error = $this->service()->save($request, $user, new Preferences(), []);

        $this->assertSame($this->translation('game/preferences.pr_error_wrong_password'), $error);
    }

    private function service(): AccountUpdateService
    {
        return new AccountUpdateService(new PreferencesService(new Preferences()));
    }

    private function translation(string $key): string
    {
        $line = __($key);
        $this->assertIsString($line);

        return $line;
    }
}
