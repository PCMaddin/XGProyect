<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\ShipyardController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;
use Xgp\App\Core\Enumerators\DefensesEnumerator as Defenses;
use Xgp\App\Core\Enumerators\ShipsEnumerator as Ships;

#[CoversClass(ShipyardController::class)]
class ShipyardControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function missileProvider(): iterable
    {
        yield 'anti-ballistic missile' => [Defenses::defense_anti_ballistic_missile, true];
        yield 'interplanetary missile' => [Defenses::defense_interplanetary_missile, true];
        yield 'a ship is not a missile' => [Ships::ship_light_fighter, false];
        yield 'a shield dome is not a missile' => [Defenses::defense_small_shield_dome, false];
    }

    #[DataProvider('missileProvider')]
    public function testIsMissileOnlyMatchesTheTwoMissileTypes(int $itemId, bool $expected): void
    {
        $controller = app(ShipyardController::class);

        $method = new ReflectionMethod(ShipyardController::class, 'isMissile');

        $this->assertSame($expected, $method->invoke($controller, $itemId));
    }

    public function testProcessQueueToArrayAggregatesAmountsPerItem(): void
    {
        $controller = app(ShipyardController::class);

        $planet = (new ReflectionClass(ShipyardController::class))->getProperty('planet');
        $planet->setValue($controller, ['planet_b_hangar_id' => '202,5;203,10;202,3;']);

        $result = (new ReflectionMethod(ShipyardController::class, 'processQueueToArray'))->invoke($controller);

        $this->assertSame([202 => 8, 203 => 10], $result);
    }
}
