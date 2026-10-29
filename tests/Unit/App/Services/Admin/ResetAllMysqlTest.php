<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Admin;

use App\Models\User;
use App\Services\Admin\ResetService;
use App\Services\RegistrationService;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * The full reset starts every player over with a new home planet; queues,
 * items and boosters go too (with the foreign key checks off nothing
 * cascades, so they stayed behind before).
 */
#[CoversClass(ResetService::class)]
class ResetAllMysqlTest extends MysqlTestCase
{
    public function testEverythingStartsOver(): void
    {
        config(['version.files' => '4.0.0']);
        $this->seed(OptionsTableSeeder::class);

        $player = app(RegistrationService::class)->register(['name' => 'Player', 'email' => 'player@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $player);
        $planetId = (int) $player->fresh()?->home_planet_id;

        DB::table('user_items')->insert(['user_id' => $player->id, 'item' => 'kraken_gold', 'amount' => 2]);
        DB::table('planet_boosters')->insert(['planet_id' => $planetId, 'resource' => 'metal', 'percent' => 10, 'starts_at' => 1, 'ends_at' => time() + 100]);
        DB::table('building_queues')->insert(['planet_id' => $planetId, 'position' => 1, 'building_id' => 1, 'target_level' => 1, 'mode' => 'build', 'duration' => 10, 'end_time' => time() + 10]);

        app(ResetService::class)->resetAll();

        foreach (['user_items', 'planet_boosters', 'building_queues', 'research_queues'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $newPlanet = (int) $player->fresh()?->home_planet_id;
        $this->assertNotSame($planetId, $newPlanet);
        $this->assertSame(1, DB::table('planets')->where('planet_id', $newPlanet)->where('planet_user_id', $player->id)->count());
    }
}
