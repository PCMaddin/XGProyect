<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\GalaxyController;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use App\Libraries\GalaxyLib;

#[CoversClass(GalaxyController::class)]
class GalaxyControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int, 2: int}>
     */
    public static function navigationProvider(): iterable
    {
        yield 'plain values pass through' => [['galaxy' => 3, 'system' => 40], 3, 40];
        yield 'galaxy steps right' => [['galaxy' => 3, 'system' => 1, 'galaxyRight' => '1'], 4, 1];
        yield 'galaxy wraps past the last one' => [['galaxy' => 9, 'system' => 1, 'galaxyRight' => '1'], 1, 1];
        yield 'galaxy wraps below the first one' => [['galaxy' => 1, 'system' => 1, 'galaxyLeft' => '1'], 9, 1];
        yield 'system wraps past the last one' => [['galaxy' => 1, 'system' => 499, 'systemRight' => '1'], 1, 1];
        yield 'system wraps below the first one' => [['galaxy' => 1, 'system' => 1, 'systemLeft' => '1'], 1, 499];
        yield 'missing values clamp to one' => [[], 1, 1];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('navigationProvider')]
    public function testNavigatePositionWrapsAroundTheUniverseBounds(array $query, int $expectedGalaxy, int $expectedSystem): void
    {
        $controller = app(GalaxyController::class);
        $method = new ReflectionMethod(GalaxyController::class, 'navigatePosition');

        /** @var array{galaxy: int, system: int, planet: int} $result */
        $result = $method->invoke($controller, Request::create('/', 'GET', $query));

        $this->assertSame($expectedGalaxy, $result['galaxy']);
        $this->assertSame($expectedSystem, $result['system']);
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function planetTypeProvider(): iterable
    {
        yield 'debris resolves to planet' => [GalaxyLib::DEBRIS_TYPE, GalaxyLib::PLANET_TYPE];
        yield 'planet stays a planet' => [GalaxyLib::PLANET_TYPE, GalaxyLib::PLANET_TYPE];
        yield 'moon stays a moon' => [GalaxyLib::MOON_TYPE, GalaxyLib::MOON_TYPE];
    }

    #[DataProvider('planetTypeProvider')]
    public function testTargetLookupPlanetTypeMapsDebrisOntoPlanets(int $planetType, int $expected): void
    {
        $controller = app(GalaxyController::class);
        $method = new ReflectionMethod(GalaxyController::class, 'targetLookupPlanetType');

        $this->assertSame($expected, $method->invoke($controller, $planetType));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function missileTargetProvider(): iterable
    {
        yield 'all keyword is valid' => ['all', true];
        yield 'lowest defence slot' => ['0', true];
        yield 'highest defence slot' => ['8', true];
        yield 'out of range' => ['9', false];
        yield 'negative' => ['-1', false];
        yield 'garbage' => ['xyz', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('missileTargetProvider')]
    public function testIsValidMissileTargetAcceptsSlotsAndAll(string $target, bool $expected): void
    {
        $controller = app(GalaxyController::class);
        $method = new ReflectionMethod(GalaxyController::class, 'isValidMissileTarget');

        $this->assertSame($expected, $method->invoke($controller, $target));
    }
}
