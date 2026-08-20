<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\PlanetLib;
use App\Models\Buildings;
use App\Models\Defenses;
use App\Models\Planets;
use App\Models\Ships;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator;

#[CoversClass(PlanetLib::class)]
class PlanetLibTest extends DatabaseTestCase
{
    private function seedSettings(): void
    {
        DB::table('options')->insert([
            ['name' => 'initial_fields', 'value' => '163', 'type' => '32'],
            ['name' => 'metal_basic_income', 'value' => '0', 'type' => '32'],
            ['name' => 'crystal_basic_income', 'value' => '0', 'type' => '32'],
            ['name' => 'deuterium_basic_income', 'value' => '0', 'type' => '32'],
        ]);
    }

    public function testSetNewPlanetCreatesThePlanetWithItsChildRows(): void
    {
        $this->seedSettings();

        $created = (new PlanetLib())->setNewPlanet(4, 5, 6, 7);

        $this->assertTrue($created);

        $planet = Planets::query()
            ->where('planet_galaxy', 4)
            ->where('planet_system', 5)
            ->where('planet_planet', 6)
            ->first();

        $this->assertNotNull($planet);
        $this->assertSame(7, $planet->planet_user_id);
        $this->assertSame(PlanetTypesEnumerator::PLANET, $planet->planet_type);
        // the child rows the game expects on a fresh planet
        $this->assertSame(1, Buildings::query()->where('building_planet_id', $planet->planet_id)->count());
        $this->assertSame(1, Defenses::query()->where('defense_planet_id', $planet->planet_id)->count());
        $this->assertSame(1, Ships::query()->where('ship_planet_id', $planet->planet_id)->count());
    }

    public function testSetNewPlanetRefusesToOverwriteAnExistingPlanet(): void
    {
        $this->seedSettings();
        $creator = new PlanetLib();

        $this->assertTrue($creator->setNewPlanet(1, 1, 1, 7));
        $this->assertFalse($creator->setNewPlanet(1, 1, 1, 9));

        // still exactly one planet on that spot, owned by the first user
        $this->assertSame(1, Planets::query()->where(['planet_galaxy' => 1, 'planet_system' => 1, 'planet_planet' => 1])->count());
        $this->assertSame(7, Planets::query()->where(['planet_galaxy' => 1, 'planet_system' => 1, 'planet_planet' => 1])->value('planet_user_id'));
    }

    public function testSetNewMoonCreatesAMoonForAnExistingPlanet(): void
    {
        $this->seedSettings();
        $creator = new PlanetLib();
        $creator->setNewPlanet(2, 3, 4, 7);

        $created = $creator->setNewMoon(2, 3, 4, 7, 'Luna', 20);

        $this->assertTrue($created);
        $this->assertSame(1, Planets::query()
            ->where(['planet_galaxy' => 2, 'planet_system' => 3, 'planet_planet' => 4, 'planet_type' => PlanetTypesEnumerator::MOON])
            ->count());
    }

    public function testSetNewMoonDoesNotCreateASecondMoon(): void
    {
        $this->seedSettings();
        $creator = new PlanetLib();
        $creator->setNewPlanet(2, 3, 4, 7);

        $this->assertTrue($creator->setNewMoon(2, 3, 4, 7));
        $this->assertFalse($creator->setNewMoon(2, 3, 4, 7));

        $this->assertSame(1, Planets::query()
            ->where(['planet_galaxy' => 2, 'planet_system' => 3, 'planet_planet' => 4, 'planet_type' => PlanetTypesEnumerator::MOON])
            ->count());
    }
}
