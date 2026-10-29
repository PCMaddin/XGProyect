<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Formulas;

use App\Services\Game\Formulas\FormulasService;
use App\Services\SettingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(FormulasService::class)]
class FormulasServiceTest extends TestCase
{
    private function service(): FormulasService
    {
        return new FormulasService(new SettingsService());
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function phalanxProvider(): iterable
    {
        yield 'no phalanx' => [0, 0];
        yield 'level 1' => [1, 1];
        yield 'level 5' => [5, 24];
    }

    #[DataProvider('phalanxProvider')]
    public function testPhalanxRange(int $level, int $expected): void
    {
        $this->assertSame($expected, $this->service()->phalanxRange($level));
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function missileProvider(): iterable
    {
        yield 'no drive' => [0, 0];
        yield 'level 3' => [3, 14];
    }

    #[DataProvider('missileProvider')]
    public function testMissileRange(int $level, int $expected): void
    {
        $this->assertSame($expected, $this->service()->missileRange($level));
    }

    public function testCalculatePlanetFields(): void
    {
        $this->assertSame(163, $this->service()->calculatePlanetFields(12800));
    }

    public function testIonTechnologyBonus(): void
    {
        $this->assertSame(0.2, $this->service()->getIonTechnologyBonus(5));
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: float}>
     */
    public static function plasmaProvider(): iterable
    {
        yield 'metal' => [10, 'metal', 0.1];
        yield 'crystal' => [10, 'crystal', 0.066];
        yield 'deuterium' => [10, 'deuterium', 0.033];
    }

    #[DataProvider('plasmaProvider')]
    public function testPlasmaTechnologyBonus(int $level, string $resource, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, $this->service()->getPlasmaTechnologyBonus($level, $resource), 0.0001);
    }

    public function testDevelopmentCostGrowsByFactor(): void
    {
        // price 100, factor 2, level 3 -> 100 * 2^3 = 800
        $this->assertSame(800.0, $this->service()->getDevelopmentCost(100, 2.0, 3));
    }

    public function testTearDownBaseCostReturnsInt(): void
    {
        // level - 2 => 100 * 2^1 = 200
        $this->assertSame(200, $this->service()->getTearDownBaseCost(100, 2.0, 3));
    }

    public function testTearDownCostAppliesIonBonusAndNeverGoesNegative(): void
    {
        // base 200, ion level 5 -> 200 * (1 - 0.2) = 160
        $this->assertSame(160, $this->service()->getTearDownCost(100, 2.0, 3, 5));
        // an extreme ion level would drive the discount below zero; it is clamped
        $this->assertSame(0, $this->service()->getTearDownCost(100, 2.0, 3, 100));
    }

    public function testMoonDestructionChanceIsCappedAtHundred(): void
    {
        $this->assertSame(100, $this->service()->getMoonDestructionChance(1, 20));
    }
}
