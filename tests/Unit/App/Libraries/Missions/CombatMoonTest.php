<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\PlanetLib;
use App\Services\FormatService;
use App\Models\Planets;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use ReflectionProperty;
use Tests\DatabaseTestCase;
use App\Core\Enumerators\PlanetTypesEnumerator;
use App\Libraries\Missions\Attack;
use App\Libraries\Missions\Destroy;

/**
 * Regression tests for two crashes of the combat missions:
 *
 * - Attack: creating a moon from the battle debris read `planet_id` from the
 *   empty "no moon yet" row ("undefined array key").
 * - Destroy: a won battle without surviving death stars read the death star
 *   count from a missing key ("undefined array key").
 *
 * Plus the moon diameter from the battle and the death star losses.
 */
class CombatMoonTest extends DatabaseTestCase
{
    private static function moons(): int
    {
        return Planets::query()
            ->where(['planet_galaxy' => 1, 'planet_system' => 5, 'planet_planet' => 7, 'planet_type' => PlanetTypesEnumerator::MOON])
            ->count();
    }

    /**
     * @phpstan-impure
     */
    private static function destruction(Destroy $destroy): mixed
    {
        return (new ReflectionProperty(Destroy::class, 'destruction'))->getValue($destroy);
    }

    public function testAttackCreatesTheMoonOnlyOnce(): void
    {
        DB::table('options')->insert([
            ['name' => 'initial_fields', 'value' => '163', 'type' => '32'],
            ['name' => 'metal_basic_income', 'value' => '0', 'type' => '32'],
            ['name' => 'crystal_basic_income', 'value' => '0', 'type' => '32'],
            ['name' => 'deuterium_basic_income', 'value' => '0', 'type' => '32'],
        ]);
        (new PlanetLib())->setNewPlanet(1, 5, 7, 2);

        $attack = app(Attack::class);
        $createMoon = new ReflectionMethod(Attack::class, 'createMoonIfMissing');

        $createMoon->invoke($attack, 1, 5, 7, 2, 7654);
        $this->assertSame(1, self::moons());

        $createMoon->invoke($attack, 1, 5, 7, 2, 3000);
        $this->assertSame(1, self::moons());

        // the diameter rolled by the battle, not a random one
        $this->assertSame(7654, Planets::query()
            ->where(['planet_galaxy' => 1, 'planet_system' => 5, 'planet_planet' => 7, 'planet_type' => PlanetTypesEnumerator::MOON])
            ->value('planet_diameter'));
    }

    public function testDestroyWithoutSurvivingDeathStarsMakesNoAttempt(): void
    {
        $destroy = new RecordingDestroy(app(FormatService::class));
        $onVictory = new ReflectionMethod(Destroy::class, 'onAttackerVictory');

        $this->assertSame([206 => 10], $onVictory->invoke($destroy, 1, ['planet_diameter' => 8000], [206 => 10]));
        $this->assertSame(
            ['attempted' => false, 'destroyed' => 'none', 'moonChance' => 0, 'dsChance' => 0],
            self::destruction($destroy)
        );
        $this->assertSame([], $destroy->recordedCalls());
    }

    /**
     * Regression: when the death stars were destroyed, the whole attacking
     * fleet was removed and the lost ships were not counted.
     */
    public function testDestroyedDeathStarsAreTheOnlyLoss(): void
    {
        $destroy = new RecordingDestroy(app(FormatService::class));
        $onVictory = new ReflectionMethod(Destroy::class, 'onAttackerVictory');

        // seed 3: the moon survives, the death stars are destroyed
        mt_srand(3);
        $returning = $onVictory->invoke($destroy, 1, ['planet_diameter' => 8000], [206 => 10, 214 => 2]);

        $this->assertSame(
            ['attempted' => true, 'destroyed' => 'ds', 'moonChance' => 15, 'dsChance' => 45],
            self::destruction($destroy)
        );
        $this->assertSame([206 => 10], $returning);
        $this->assertSame([['updateLostShipsAndDefensePoints', 1, [214 => 2]]], $destroy->recordedCalls());
    }
}
