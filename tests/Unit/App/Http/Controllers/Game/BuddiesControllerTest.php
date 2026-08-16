<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\BuddiesController;
use App\Services\SettingsService;
use App\Services\TimingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(BuddiesController::class)]
class BuddiesControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: int, 2: ?string}>
     */
    public static function actionProvider(): iterable
    {
        yield 'mode 2 shows the request form' => [2, 0, 'form'];
        yield 'mode 1 sm 1 removes' => [1, 1, 'remove'];
        yield 'mode 1 sm 2 accepts' => [1, 2, 'accept'];
        yield 'mode 1 sm 3 sends' => [1, 3, 'send'];
        yield 'mode 1 with unknown sm is null' => [1, 9, null];
        yield 'unknown mode is null' => [3, 1, null];
        yield 'no mode is null' => [0, 0, null];
    }

    #[DataProvider('actionProvider')]
    public function testResolveActionMapsModeAndSmToConcreteActions(int $mode, int $sm, ?string $expected): void
    {
        $controller = new BuddiesController(new TimingService(new SettingsService()));

        $method = new ReflectionMethod(BuddiesController::class, 'resolveAction');

        $this->assertSame($expected, $method->invoke($controller, $mode, $sm));
    }
}
