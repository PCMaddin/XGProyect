<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\MovementController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(MovementController::class)]
class MovementControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function missionProvider(): iterable
    {
        yield 'attack' => [1, 'Attack'];
        yield 'transport' => [3, 'Transport'];
        yield 'recycle' => [8, 'Recycle'];
        yield 'unknown mission id falls back to empty' => [999, ''];
    }

    #[DataProvider('missionProvider')]
    public function testMissionNameResolvesTheTranslationTable(int $mission, string $expected): void
    {
        $controller = app(MovementController::class);
        $method = new ReflectionMethod(MovementController::class, 'missionName');

        $this->assertSame($expected, $method->invoke($controller, $mission));
    }

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function returningProvider(): iterable
    {
        yield 'outbound fleet' => [0, false];
        yield 'returning fleet' => [1, true];
    }

    #[DataProvider('returningProvider')]
    public function testTitleAndTooltipReflectTheReturningState(int $fleetMess, bool $isReturning): void
    {
        $controller = app(MovementController::class);
        $title = (new ReflectionMethod(MovementController::class, 'buildTitleBlock'))->invoke($controller, $fleetMess);
        $tooltip = (new ReflectionMethod(MovementController::class, 'buildToolTipBlock'))->invoke($controller, $fleetMess);

        $this->assertSame((string) __('game/fleet.' . ($isReturning ? 'fl_r' : 'fl_a')), $title);
        $this->assertSame((string) __('game/fleet.' . ($isReturning ? 'fl_returning' : 'fl_onway')), $tooltip);
    }

    public function testEmptyMovementRowUsesPlaceholders(): void
    {
        $controller = app(MovementController::class);
        $method = new ReflectionMethod(MovementController::class, 'emptyMovementRow');

        /** @var array<string, string> $row */
        $row = $method->invoke($controller);

        $this->assertSame('-', $row['num']);
        $this->assertSame('', $row['title']);
        $this->assertSame('-', $row['fleet_actions']);
    }
}
