<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\Fleet2Controller;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator as PlanetTypes;

#[CoversClass(Fleet2Controller::class)]
class Fleet2ControllerTest extends TestCase
{
    public function testPlanetTypesBlockIsEmptyWithoutPostData(): void
    {
        $controller = app(Fleet2Controller::class);
        $method = new ReflectionMethod(Fleet2Controller::class, 'buildPlanetTypesBlock');

        $result = $method->invoke($controller, Request::create('/', 'GET'));

        $this->assertSame([], $result);
    }

    public function testPlanetTypesBlockMarksTheSubmittedType(): void
    {
        $controller = app(Fleet2Controller::class);
        $method = new ReflectionMethod(Fleet2Controller::class, 'buildPlanetTypesBlock');

        /** @var array<int, array<string, mixed>> $result */
        $result = $method->invoke($controller, Request::create('/', 'POST', ['planet_type' => PlanetTypes::MOON]));

        $this->assertCount(3, $result);

        $selected = array_values(array_filter($result, fn (array $row): bool => $row['selected'] === 'selected'));
        $this->assertCount(1, $selected);
        $this->assertSame(PlanetTypes::MOON, $selected[0]['value']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function targetProvider(): iterable
    {
        yield 'missing falls back' => [[], 7];
        yield 'valid passes through' => [['galaxy' => 4], 4];
        yield 'zero falls back' => [['galaxy' => 0], 7];
        yield 'over range falls back' => [['galaxy' => 99999], 7];
    }

    /**
     * @param  array<string, mixed>  $post
     */
    #[DataProvider('targetProvider')]
    public function testTargetInputClampsToDefault(array $post, int $expected): void
    {
        $controller = app(Fleet2Controller::class);
        $method = new ReflectionMethod(Fleet2Controller::class, 'targetInput');

        $result = $method->invoke(
            $controller,
            Request::create('/', 'POST', $post),
            'galaxy',
            1,
            MAX_GALAXY_IN_WORLD,
            7
        );

        $this->assertSame($expected, $result);
    }
}
