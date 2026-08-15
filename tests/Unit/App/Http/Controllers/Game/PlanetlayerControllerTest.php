<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\PlanetlayerController;
use App\Services\FormatService;
use App\Services\Game\HomePlanetService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(PlanetlayerController::class)]
class PlanetlayerControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: int, 2: int, 3: int, 4: bool}>
     */
    public static function fleetProvider(): iterable
    {
        // own fleet departing from the planet blocks abandoning
        yield 'own fleet departing blocks' => [1, 0, 0, 0, true];
        // hostile inbound, unread, not a recall blocks
        yield 'hostile inbound blocks' => [0, 5, 0, 0, true];
        // hostile inbound but already read (mess >= 1) does not block
        yield 'read hostile does not block' => [0, 5, 1, 0, false];
        // hostile inbound that is a recall (end type 2) does not block
        yield 'recalled hostile does not block' => [0, 5, 0, 2, false];
        // no fleets does not block
        yield 'no fleet does not block' => [0, 0, 0, 0, false];
    }

    #[DataProvider('fleetProvider')]
    public function testFleetBlocksAbandonMatchesLegacyRules(
        int $ownFleet,
        int $enemyFleet,
        int $mess,
        int $endType,
        bool $expected,
    ): void {
        $controller = new PlanetlayerController(new FormatService(), new HomePlanetService());

        $method = new ReflectionMethod(PlanetlayerController::class, 'fleetBlocksAbandon');

        $this->assertSame($expected, $method->invoke($controller, $ownFleet, $enemyFleet, $mess, $endType));
    }
}
