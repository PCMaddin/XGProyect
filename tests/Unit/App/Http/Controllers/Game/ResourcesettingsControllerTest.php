<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\ResourcesettingsController;
use App\Services\FormatService;
use App\Services\Game\Formulas\FormulasService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\Game\Formulas\ProductionService;
use App\Services\SettingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(ResourcesettingsController::class)]
class ResourcesettingsControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: float, 1: float, 2: int}>
     */
    public static function prodLevelProvider(): iterable
    {
        yield 'balanced energy runs at 100%' => [-100.0, 200.0, 100];
        yield 'exact balance runs at 100%' => [-200.0, 200.0, 100];
        yield 'deficit scales down proportionally' => [-400.0, 200.0, 50];
        yield 'no production with deficit is 0%' => [-50.0, 0.0, 0];
        yield 'no energy at all runs at 100%' => [0.0, 0.0, 100];
    }

    #[DataProvider('prodLevelProvider')]
    public function testProdLevelScalesWithEnergyBalance(float $used, float $max, int $expected): void
    {
        $this->assertSame($expected, $this->invoke('prodLevel', $used, $max));
    }

    public function testDailyAndWeeklyDeriveFromHourlyProduction(): void
    {
        // 100/h at 100% level with no basic income -> 2400/day, 16800/week
        $this->assertSame(2_400.0, $this->invoke('calculateDaily', 100.0, 100, 0));
        $this->assertSame(16_800.0, $this->invoke('calculateWeekly', 100.0, 100, 0));
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $controller = new ResourcesettingsController(
            new ProductionService(),
            new FormatService(),
            new OfficerService(),
            new FormulasService(new SettingsService()),
        );

        return (new ReflectionMethod(ResourcesettingsController::class, $method))->invoke($controller, ...$args);
    }
}
