<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\Fleet1Controller;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Xgp\App\Core\Enumerators\ShipsEnumerator as Ships;

#[CoversClass(Fleet1Controller::class)]
class Fleet1ControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function positionProvider(): iterable
    {
        yield 'missing param falls back to the default' => [[], 42];
        yield 'valid value passes through' => [['system' => 123], 123];
        yield 'below range falls back' => [['system' => 0], 42];
        yield 'above range falls back' => [['system' => 999999], 42];
        yield 'non-numeric falls back' => [['system' => 'abc'], 42];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('positionProvider')]
    public function testPositionInputClampsToDefault(array $query, int $expected): void
    {
        $controller = app(Fleet1Controller::class);
        $method = new ReflectionMethod(Fleet1Controller::class, 'positionInput');

        $result = $method->invoke(
            $controller,
            Request::create('/', 'GET', $query),
            'system',
            1,
            MAX_SYSTEM_IN_GALAXY,
            42
        );

        $this->assertSame($expected, $result);
    }

    public function testSolarSatelliteHasNoInputOrMaxLink(): void
    {
        $controller = app(Fleet1Controller::class);
        $input = new ReflectionMethod(Fleet1Controller::class, 'buildShipsInput');
        $link = new ReflectionMethod(Fleet1Controller::class, 'buildMaxShipsLink');

        $this->assertNull($input->invoke($controller, Ships::ship_solar_satellite));
        $this->assertNull($link->invoke($controller, Ships::ship_solar_satellite));
    }

    public function testRegularShipRendersAnInputField(): void
    {
        $controller = app(Fleet1Controller::class);
        $input = new ReflectionMethod(Fleet1Controller::class, 'buildShipsInput');

        $result = $input->invoke($controller, Ships::ship_light_fighter);

        $this->assertIsString($result);
        $this->assertStringContainsString('name="ship' . Ships::ship_light_fighter . '"', $result);
    }
}
