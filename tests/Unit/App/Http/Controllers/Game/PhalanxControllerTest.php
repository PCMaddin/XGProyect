<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\PhalanxController;
use App\Services\Game\Formulas\FormulasService;
use App\Services\SettingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(PhalanxController::class)]
class PhalanxControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>|null, 1: bool}>
     */
    public static function moonProvider(): iterable
    {
        yield 'no moon counts as destroyed' => [null, true];
        yield 'intact moon is not destroyed' => [['planet_destroyed' => 0], false];
        yield 'moon with a destruction time is destroyed' => [['planet_destroyed' => 1_700_000_000], true];
    }

    /**
     * @param  array<string, mixed>|null  $moon
     */
    #[DataProvider('moonProvider')]
    public function testMoonDestroyedTreatsMissingMoonAsDestroyed(?array $moon, bool $expected): void
    {
        $this->assertSame($expected, $this->invoke('moonDestroyed', $moon));
    }

    /**
     * @return iterable<string, array{0: int, 1: bool, 2: bool}>
     */
    public static function visibilityProvider(): iterable
    {
        yield 'planet is always visible' => [1, false, true];
        yield 'intact moon is hidden' => [3, false, false];
        yield 'destroyed moon is visible' => [3, true, true];
    }

    #[DataProvider('visibilityProvider')]
    public function testVisibleTypeRequiresPlanetOrDestroyedMoon(int $planetType, bool $moonDestroyed, bool $expected): void
    {
        $this->assertSame($expected, $this->invoke('visibleType', $planetType, $moonDestroyed));
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $controller = new PhalanxController(new FormulasService(new SettingsService()));

        return (new ReflectionMethod(PhalanxController::class, $method))->invoke($controller, ...$args);
    }
}
