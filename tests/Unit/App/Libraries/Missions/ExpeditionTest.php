<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use App\Libraries\Missions\Expedition;

/**
 * earlyReturnEndTime(): as in the original, the return flight is shortened
 * by a third of the holding time times the factor, but takes at least a
 * second (it halved the return flight before).
 */
class ExpeditionTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: int}>
     */
    public static function earlyReturnCases(): array
    {
        return [
            // 1 hour held, return flight 2 hours: 2 / 3 hours earlier
            'factor 2' => [17200, 10000, 3600, 2, 17200 - 2400],
            'factor 5' => [17200, 10000, 3600, 5, 17200 - 6000],
            'odd thirds round down' => [17200, 10000, 1000, 2, 17200 - 666],
            // 9 hours held, short flight: back one second after the stay
            'at least a second' => [11000, 10000, 32400, 3, 10001],
        ];
    }

    #[DataProvider('earlyReturnCases')]
    public function testEarlyReturnShortensTheFlightByAThirdOfTheHoldingTimeTimesTheFactor(int $endTime, int $endStay, int $holdingTime, int $factor, int $expected): void
    {
        $ref = new ReflectionMethod(Expedition::class, 'earlyReturnEndTime');

        $this->assertSame($expected, $ref->invoke(null, $endTime, $endStay, $holdingTime, $factor));
    }

    public function testTheHoldingTimeIsTheStayAtThePosition(): void
    {
        $ref = new ReflectionMethod(Expedition::class, 'holdingTime');

        $this->assertSame(3600, $ref->invoke(null, ['fleet_start_time' => 10000, 'fleet_end_stay' => 13600]));
        $this->assertSame(0, $ref->invoke(null, ['fleet_start_time' => 10000, 'fleet_end_stay' => 9000]));
    }
}
