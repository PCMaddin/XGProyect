<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\FleetshortcutsController;
use App\Services\FormatService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(FleetshortcutsController::class)]
class FleetshortcutsControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?string}>
     */
    public static function modeProvider(): iterable
    {
        yield 'add is accepted' => ['add', 'add'];
        yield 'edit is accepted' => ['edit', 'edit'];
        yield 'delete is accepted' => ['delete', 'delete'];
        yield 'legacy "a" mode is rejected' => ['a', null];
        yield 'unknown mode is rejected' => ['nonsense', null];
        yield 'null stays null' => [null, null];
    }

    #[DataProvider('modeProvider')]
    public function testResolveModeOnlyAcceptsHandledModes(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->invoke('resolveMode', $input));
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: int, 3: bool}>
     */
    public static function rangeProvider(): iterable
    {
        yield 'within range' => [5, 1, 9, true];
        yield 'at lower bound' => [1, 1, 9, true];
        yield 'at upper bound' => [9, 1, 9, true];
        yield 'below range' => [0, 1, 9, false];
        yield 'above range' => [10, 1, 9, false];
    }

    #[DataProvider('rangeProvider')]
    public function testInRangeChecksInclusiveBounds(int $value, int $min, int $max, bool $expected): void
    {
        $this->assertSame($expected, $this->invoke('inRange', $value, $min, $max));
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $controller = new FleetshortcutsController(new FormatService());

        return (new ReflectionMethod(FleetshortcutsController::class, $method))->invoke($controller, ...$args);
    }
}
