<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Game;

use App\Libraries\Functions;
use App\Libraries\Users;
use App\Models\User;
use App\Services\RegistrationService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Tests\MysqlTestCase;

/**
 * The game pages check the session against the player's row: the player's
 * id, the login, the fingerprint of the password. A mismatch sends the
 * player to the start page. These checks ended the process with exit, so
 * no test could reach them (the run ended there, as passed).
 */
#[CoversClass(Users::class)]
#[CoversClass(Functions::class)]
class SessionGatesMysqlTest extends MysqlTestCase
{
    private User $player;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);

        $this->player = $this->register('Player');
        $this->other = $this->register('Other');
    }

    public function testAMatchingSessionShowsTheGame(): void
    {
        $this->withGameSession($this->player->id, $this->fingerprint($this->player))->get('/game.php?page=overview')->assertOk();
    }

    public function testASessionOfAnotherPlayerLeadsToTheStartPage(): void
    {
        $this->withGameSession($this->other->id, $this->fingerprint($this->other))->get('/game.php?page=overview')->assertRedirect(url('/'));
    }

    public function testAnOldPasswordLeadsToTheStartPage(): void
    {
        $this->withGameSession($this->player->id, Hash::make('old-hash-test-secret'))->get('/game.php?page=overview')->assertRedirect(url('/'));
    }

    /**
     * Logged in as the player, with the legacy session keys given.
     */
    private function withGameSession(int $userId, string $fingerprint): static
    {
        (new ReflectionProperty(Users::class, 'instance'))->setValue(null, null);

        return $this->actingAs($this->player)->withSession(['user_id' => $userId, 'user_password' => $fingerprint]);
    }

    private function fingerprint(User $user): string
    {
        return Hash::make(User::findOrFail($user->id)->getAuthPassword() . '-test-secret');
    }

    private function register(string $name): User
    {
        $user = app(RegistrationService::class)->register(['name' => $name, 'email' => strtolower($name) . '@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $user);

        return User::findOrFail($user->id);
    }
}
