<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Users\UserPlanetController;
use App\Http\Controllers\Admin\Users\UserProgressController;
use App\Http\Requests\Admin\Users\UserPremiumRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\DatabaseTestCase;

/**
 * Regression: the admin edit forms wrote every submitted field with the
 * right prefix (planet_, premium_, …) as a column name; an unknown name
 * failed the query, and keys like planet_user_id or premium_user_id could
 * be reassigned.
 */
#[CoversClass(UserPlanetController::class)]
#[CoversClass(UserProgressController::class)]
class UserEditAllowlistTest extends DatabaseTestCase
{
    public function testThePlanetFormOnlyWritesEditableColumns(): void
    {
        DB::table('planets')->insert(['planet_id' => 5, 'planet_user_id' => 1, 'planet_name' => 'Home']);

        $request = Request::create('/', 'POST', [
            'planet_name' => 'Renamed',
            'planet_metal' => '500',
            'planet_user_id' => '99',
            'planet_no_such_column' => '1',
        ]);
        (new ReflectionMethod(UserPlanetController::class, 'savePlanetData'))
            ->invoke(app(UserPlanetController::class), $request, 5);

        $planet = DB::table('planets')->where('planet_id', 5)->first(['planet_name', 'planet_metal', 'planet_user_id']);
        $this->assertNotNull($planet);
        $this->assertSame('Renamed', $planet->planet_name);
        $this->assertEquals(500, $planet->planet_metal);
        $this->assertSame(1, $planet->planet_user_id);
    }

    public function testThePremiumFormOnlyWritesOfficers(): void
    {
        $user = $this->seedUser();
        DB::table('premium')->insert(['premium_user_id' => $user->id]);

        $data = ['premium_officier_geologist' => 1, 'premium_user_id' => 99, 'premium_no_such_column' => 1];
        $request = UserPremiumRequest::create('/', 'POST', $data);
        $request->setValidator(Validator::make($data, []));

        app(UserProgressController::class)->updatePremium($request, $user);

        $this->assertSame(1, DB::table('premium')->where('premium_user_id', $user->id)->count());
        $this->assertSame(0, DB::table('premium')->where('premium_user_id', 99)->count());
    }

    private function seedUser(): User
    {
        $user = new User(['name' => 'Player', 'email' => 'player@example.com', 'password' => 'secret', 'home_planet_id' => 1, 'current_planet' => 1]);
        $user->save();

        return $user;
    }
}
