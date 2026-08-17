<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\Fleet4Controller;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;
use Xgp\App\Core\Enumerators\ShipsEnumerator as Ships;

#[CoversClass(Fleet4Controller::class)]
class Fleet4ControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int, 2: int, 3: int}>
     */
    public static function rangeProvider(): iterable
    {
        yield 'missing is zero' => [[], 0, 32, 0];
        yield 'in range passes' => [['holdingtime' => 8], 0, 32, 8];
        yield 'above range is zero' => [['holdingtime' => 64], 0, 32, 0];
        yield 'below range is zero' => [['mission' => 0], 1, 15, 0];
        yield 'non-numeric is zero' => [['mission' => 'x'], 1, 15, 0];
    }

    /**
     * @param  array<string, mixed>  $post
     */
    #[DataProvider('rangeProvider')]
    public function testRangeIntClampsToZeroOutsideBounds(array $post, int $min, int $max, int $expected): void
    {
        $controller = app(Fleet4Controller::class);
        $method = new ReflectionMethod(Fleet4Controller::class, 'rangeInt');
        $key = array_key_first($post) ?? 'holdingtime';

        $result = $method->invoke($controller, Request::create('/', 'POST', $post), $key, $min, $max);

        $this->assertSame($expected, $result);
    }

    public function testSessionShipsDecodesTheWizardEncoding(): void
    {
        $fleet = [204 => 3, 202 => 40];
        session(['fleet_data' => ['fleetarray' => str_rot13(base64_encode(serialize($fleet)))]]);

        $controller = app(Fleet4Controller::class);
        $method = new ReflectionMethod(Fleet4Controller::class, 'sessionShips');

        $this->assertSame($fleet, $method->invoke($controller));
    }

    public function testSpyMissionRequiresProbesAndAnotherPlanet(): void
    {
        $controller = app(Fleet4Controller::class);
        (new ReflectionProperty(Fleet4Controller::class, 'ownPlanet'))->setValue($controller, false);
        $method = new ReflectionMethod(Fleet4Controller::class, 'isMissionShipValid');

        $this->assertTrue($method->invoke($controller, Missions::SPY, [Ships::ship_espionage_probe => 1]));
        $this->assertFalse($method->invoke($controller, Missions::SPY, [Ships::ship_light_fighter => 1]));
    }

    public function testAttackOnOwnPlanetIsInvalid(): void
    {
        $controller = app(Fleet4Controller::class);
        (new ReflectionProperty(Fleet4Controller::class, 'ownPlanet'))->setValue($controller, true);
        $method = new ReflectionMethod(Fleet4Controller::class, 'isMissionShipValid');

        $this->assertFalse($method->invoke($controller, Missions::ATTACK, []));
    }
}
