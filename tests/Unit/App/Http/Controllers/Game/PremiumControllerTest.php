<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\PremiumController;
use App\Services\FormatService;
use App\Services\Game\Formulas\OfficerService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(PremiumController::class)]
class PremiumControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: int, 2: int, 3: bool, 4: int}>
     */
    public static function expiryProvider(): iterable
    {
        // active officer: new time stacks on top of the current expiry
        yield 'active stacks on current expiry' => [2_000, 500, 1_000, true, 2_500];
        // inactive officer: new time starts from "now"
        yield 'inactive starts from now' => [2_000, 500, 1_000, false, 1_500];
        yield 'expired long ago restarts from now' => [10, 500, 1_000, false, 1_500];
    }

    #[DataProvider('expiryProvider')]
    public function testPurchaseExpiryStacksOnlyWhenActive(
        int $currentExpiry,
        int $duration,
        int $now,
        bool $active,
        int $expected,
    ): void {
        $controller = new PremiumController(new FormatService(), new OfficerService());

        $method = new ReflectionMethod(PremiumController::class, 'purchaseExpiry');

        $this->assertSame($expected, $method->invoke($controller, $currentExpiry, $duration, $now, $active));
    }
}
