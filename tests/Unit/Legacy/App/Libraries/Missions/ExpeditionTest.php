<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries\Missions;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Xgp\App\Libraries\Missions\Expedition;

/**
 * - earlyReturnEndTime(): regression for the TypeError on odd return times.
 * - blackHoleLosses(): compared with a verbatim copy of the original loop in
 *   resultBlackHole().
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
     * Verbatim loop of the original resultBlackHole().
     *
     * @param array<int, int> $ships
     *
     * @return array{lost: array<int, float>, remaining: array<int, float>, lostAll: bool}
     */
    private static function originalLosses(array $ships, float $lostChances): array
    {
        $newShips = [];
        $lostShips = [];
        $lostAll = true;

        foreach ($ships as $ship => $amount) {
            if (floor($amount * $lostChances) != 0) {
                $lostShips[$ship] = floor($amount * $lostChances);
                $newShips[$ship] = ($amount - $lostShips[$ship]);
                $lostAll = false;
            }
        }

        return ['lost' => $lostShips, 'remaining' => $newShips, 'lostAll' => $lostAll];
    }

    /**
     * @return array<string, array{0: array<int, int>}>
     */
    public static function fleets(): array
    {
        return [
            'small fleet' => [[202 => 50, 210 => 3]],
            'mixed fleet' => [[202 => 150, 210 => 2, 213 => 7]],
            'single ships' => [[204 => 1, 205 => 1]],
            'large fleet' => [[203 => 1000, 207 => 333, 215 => 12]],
        ];
    }

    /**
     * @param array<int, int> $ships
     */
    #[DataProvider('fleets')]
    public function testBlackHoleLossesMatchTheOriginalLoop(array $ships): void
    {
        $ref = new ReflectionMethod(Expedition::class, 'blackHoleLosses');

        // the possible chances of resultBlackHole() other than 1 (total loss)
        foreach ([0, 1, 2] as $roll) {
            $lostChances = ($roll * 33 + 1) / 100;

            $this->assertSame(
                self::originalLosses($ships, $lostChances),
                $ref->invoke(null, $ships, $lostChances),
                "lost chances {$lostChances}"
            );
        }
    }

    public function testBlackHoleWithoutAnyLostUnitDestroysTheWholeFleet(): void
    {
        $ref = new ReflectionMethod(Expedition::class, 'blackHoleLosses');

        $this->assertSame(
            ['lost' => [], 'remaining' => [], 'lostAll' => true],
            $ref->invoke(null, [202 => 50, 210 => 3], 0.01)
        );
    }
}
