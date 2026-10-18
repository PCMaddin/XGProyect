<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\SuspicionsController;
use App\Models\User;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The admin sees the signs with their evidence and decides: fine, or
 * excluded from the season's rankings and prizes.
 */
#[CoversClass(SuspicionsController::class)]
class SuspicionsControllerTest extends DatabaseTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);

        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'home_planet_id' => 1, 'current_planet' => 1]);
        $admin->authlevel = 3;
        $admin->save();
        $this->admin = $admin;
    }

    public function testTheSignsAreListedAndDecided(): void
    {
        $alpha = $this->player('Alpha');
        $beta = $this->player('Beta');
        DB::table('users_statistics')->insert(['user_statistic_user_id' => $alpha, 'user_statistic_total_rank' => 2]);
        DB::table('suspicions')->insert([
            'id' => 1, 'user_id' => $alpha, 'kind' => 'pushing', 'other_user' => $beta, 'score' => 90,
            'detail' => '{"resources":130000}', 'status' => 'open', 'created_at' => time(), 'updated_at' => time(),
        ]);

        $this->actingAs($this->admin)->get('/admin/suspicions')->assertOk()
            ->assertSee('Suspicions')->assertSee('Alpha')->assertSee('place 2')->assertSee('sends a stronger player resources')
            ->assertSee('with Beta')->assertSee('net resources: 130.000');

        $this->actingAs($this->admin)->post('/admin/suspicions/1', ['status' => 'excluded', 'note' => 'feeds Beta'])->assertRedirect(route('admin.suspicions'));
        $this->assertSame(['excluded', 'feeds Beta'], [DB::table('suspicions')->where('id', 1)->value('status'), DB::table('suspicions')->where('id', 1)->value('note')]);

        $this->actingAs($this->admin)->post('/admin/suspicions/1', ['status' => 'deleted'])->assertSessionHas('danger');
        $this->assertSame('excluded', DB::table('suspicions')->where('id', 1)->value('status'));
    }

    public function testWithoutSignsThePageSaysSo(): void
    {
        $this->actingAs($this->admin)->get('/admin/suspicions')->assertOk()->assertSee('No signs.');
    }

    private function player(string $name): int
    {
        return (int) DB::table('users')->insertGetId(['name' => $name, 'email' => strtolower($name) . '@example.com', 'password' => 'x', 'home_planet_id' => crc32($name), 'current_planet' => crc32($name)]);
    }
}
