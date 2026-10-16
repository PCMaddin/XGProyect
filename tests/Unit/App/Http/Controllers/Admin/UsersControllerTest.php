<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Users\UsersController;
use App\Http\Requests\Admin\Users\UserInfoRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

#[CoversClass(UsersController::class)]
class UsersControllerTest extends DatabaseTestCase
{
    /**
     * Regression: the new password was hashed before the model's password
     * mutator hashed it again, so the player could not log in with it.
     */
    public function testAPasswordSetByAnAdminWorksForLogin(): void
    {
        [$admin, $player] = $this->seedUsers();

        $this->updateInfo($admin, $player, ['password' => 'new-secret']);

        $hash = DB::table('users')->where('id', $player->id)->value('password');
        $this->assertIsString($hash);
        $this->assertTrue(Hash::check('new-secret', $hash));
    }

    /**
     * The rank is not mass assignable any more; the admin form sets it explicitly.
     */
    public function testAnAdminCanStillChangeTheRank(): void
    {
        [$admin, $player] = $this->seedUsers();

        $this->updateInfo($admin, $player, ['authlevel' => 1]);

        $this->assertSame(1, DB::table('users')->where('id', $player->id)->value('authlevel'));
    }

    public function testTheRankIsNotMassAssignable(): void
    {
        $user = new User(['name' => 'Mallory', 'authlevel' => 3]);

        $this->assertSame('Mallory', $user->name);
        $this->assertNull($user->getAttributes()['authlevel'] ?? null);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function seedUsers(): array
    {
        $users = [];

        foreach ([['Admin', 3], ['Player', 0]] as $i => [$name, $level]) {
            $user = new User([
                'name' => $name,
                'email' => strtolower($name) . '@example.com',
                'password' => 'secret',
                'home_planet_id' => $i + 1,
                'current_planet' => $i + 1,
            ]);
            $user->authlevel = $level;
            $user->save();
            $users[] = $user;
        }

        return [$users[0], $users[1]];
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function updateInfo(User $admin, User $player, array $changes): void
    {
        $this->actingAs($admin);
        $data = $changes + [
            'username' => $player->name,
            'email' => $player->email,
            'authlevel' => 0,
            'home_planet_id' => 2,
            'current_planet' => 2,
            'ally_id' => 0,
        ];

        $request = UserInfoRequest::create('/', 'POST', $data);
        $request->setValidator(Validator::make($data, array_fill_keys(array_keys($data), 'nullable')));

        app(UsersController::class)->updateInfo($request, $player);
    }
}
