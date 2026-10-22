<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use App\Libraries\Missions\DebrisLoad;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Characterization of how recyclers split a debris field. The expected
 * values are golden values captured from the original nested if/else
 * implementation before it was flattened into splitRecycledDebris() (now
 * DebrisLoad::split(), shared with the reapers).
 */
#[CoversClass(DebrisLoad::class)]
class RecycleTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int|float, 4: int|float}>
     */
    public static function debrisCases(): array
    {
        return [
            'everything fits' => [3000, 2000, 20000, 3000, 2000],
            'exact fit' => [10000, 10000, 20000, 10000, 10000],
            'both exceed half' => [50000, 30000, 20000, 10000, 10000],
            'metal bigger, crystal small' => [50000, 4000, 20000, 16000, 4000],
            'crystal bigger, metal small' => [3000, 60000, 20000, 3000, 17000],
            'odd capacity' => [9001, 9001, 15001, 7500.5, 7500.5],
            // not reachable in practice (a fleet never carries more than it can
            // hold); a negative capacity no longer unloads the fleet
            'negative capacity' => [1000, 500, -300, 0, 0],
        ];
    }

    #[DataProvider('debrisCases')]
    public function testSplitRecycledDebrisMatchesTheOriginalAlgorithm(
        int $metal,
        int $crystal,
        int $capacity,
        int | float $expectedMetal,
        int | float $expectedCrystal
    ): void {
        $result = DebrisLoad::split($metal, $crystal, $capacity);

        $this->assertEqualsWithDelta($expectedMetal, $result['metal'], 0.0001);
        $this->assertEqualsWithDelta($expectedCrystal, $result['crystal'], 0.0001);
    }
}
