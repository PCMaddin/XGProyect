<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services;

use App\Services\Game\PlanetService;
use App\Services\RegistrationService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

#[CoversClass(RegistrationService::class)]
class RegistrationServiceTest extends DatabaseTestCase
{
    public function testThePlayerIsCreatedWithAHomePlanet(): void
    {
        DB::table('options')->insert([
            ['name' => 'initial_fields', 'value' => '163', 'type' => '32'],
            ['name' => 'metal_basic_income', 'value' => '0', 'type' => '32'],
            ['name' => 'crystal_basic_income', 'value' => '0', 'type' => '32'],
            ['name' => 'deuterium_basic_income', 'value' => '0', 'type' => '32'],
        ]);

        $user = $this->service()->register($this->attributes());

        $this->assertNotNull($user);
        $row = DB::table('users')->where('id', $user->id)->first(['home_planet_id', 'current_planet', 'galaxy', 'system', 'planet']);
        $this->assertNotNull($row);
        $this->assertGreaterThan(0, $row->home_planet_id);
        $this->assertSame($row->home_planet_id, $row->current_planet);
        $this->assertSame([1, 2, 3], [$row->galaxy, $row->system, $row->planet]);
        $this->assertSame(1, DB::table('preferences')->where('preference_user_id', $user->id)->count());
    }

    /**
     * Regression: after a failure the rolled-back user was still returned,
     * and the welcome message and email went to a player that did not exist.
     */
    public function testNothingIsKeptWhenTheRegistrationFails(): void
    {
        DB::statement('DROP TABLE ' . DB::getTablePrefix() . 'planets');

        $this->assertNull($this->service()->register($this->attributes()));
        $this->assertSame(0, DB::table('users')->count());
    }

    private function service(): RegistrationService
    {
        $planets = $this->createStub(PlanetService::class);
        $planets->method('calculateNewPlanetPosition')->willReturn(['galaxy' => 1, 'system' => 2, 'planet' => 3]);

        return new RegistrationService($planets);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        return ['name' => 'NewPlayer', 'email' => 'new@example.com', 'password' => 'secret'];
    }
}
