<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\TraderResourcesController;
use App\Services\FormatService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(TraderResourcesController::class)]
class TraderResourcesControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: array{0: string, 1: int}|null}>
     */
    public static function refillKeyProvider(): iterable
    {
        yield 'metal 10%' => ['metal-10', ['metal', 10]];
        yield 'crystal 50%' => ['crystal-50', ['crystal', 50]];
        yield 'deuterium 100%' => ['deuterium-100', ['deuterium', 100]];
        yield 'unknown resource is rejected' => ['energy-50', null];
        yield 'unknown percentage is rejected' => ['metal-25', null];
        yield 'unanchored junk is rejected' => ['x metal-50 y', null];
        yield 'plain field is rejected' => ['action', null];
    }

    /**
     * @param  array{0: string, 1: int}|null  $expected
     */
    #[DataProvider('refillKeyProvider')]
    public function testParseRefillKeyOnlyAcceptsWhitelistedButtons(string $key, ?array $expected): void
    {
        $controller = new TraderResourcesController(new FormatService());

        $method = new ReflectionMethod(TraderResourcesController::class, 'parseRefillKey');

        $this->assertSame($expected, $method->invoke($controller, $key));
    }
}
