<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries\Missions;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Xgp\App\Libraries\Missions\Expedition;

/**
 * - earlyReturnEndTime(): regression for the TypeError on odd return times.
 * - blackHoleLosses(): only the lost ships leave the fleet.
 */
class ExpeditionTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function earlyReturnCases(): array
    {
        return [
            'even return time' => [1000, 800, 900],
            'odd return time' => [1001, 800, 901],
            'no return time' => [800, 800, 800],
        ];
    }

    #[DataProvider('earlyReturnCases')]
    public function testEarlyReturnEndTimeHalvesTheReturnTime(int $endTime, int $endStay, int $expected): void
    {
        $ref = new ReflectionMethod(Expedition::class, 'earlyReturnEndTime');

        $this->assertSame($expected, $ref->invoke(null, $endTime, $endStay));
    }

    /**
     * @return array<string, array{0: array<int, int>, 1: float, 2: array<int, int>, 3: array<int, int>}>
     */
    public static function blackHoleCases(): array
    {
        return [
            'too few ships to lose any' => [[202 => 50, 210 => 3], 0.01, [], [202 => 50, 210 => 3]],
            'only some types lose ships' => [[202 => 150, 210 => 2], 0.34, [202 => 51], [202 => 99, 210 => 2]],
            'every type loses ships' => [[203 => 1000, 207 => 333, 215 => 12], 0.67, [203 => 670, 207 => 223, 215 => 8], [203 => 330, 207 => 110, 215 => 4]],
            'a single ship survives 67 %' => [[204 => 1, 205 => 1], 0.67, [], [204 => 1, 205 => 1]],
        ];
    }

    /**
     * Regression: ship types without losses used to vanish from the fleet,
     * and a fleet without any loss was destroyed completely.
     *
     * @param array<int, int> $ships
     * @param array<int, int> $lost
     * @param array<int, int> $remaining
     */
    #[DataProvider('blackHoleCases')]
    public function testBlackHoleLosesOnlyTheLostShips(array $ships, float $lostChances, array $lost, array $remaining): void
    {
        $ref = new ReflectionMethod(Expedition::class, 'blackHoleLosses');

        $this->assertSame(['lost' => $lost, 'remaining' => $remaining], $ref->invoke(null, $ships, $lostChances));
    }
}
