<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\MissionControlLib;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(MissionControlLib::class)]
class MissionControlLibTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function missionProvider(): iterable
    {
        yield 'attack' => [1, true];
        yield 'acs' => [2, true];
        yield 'transport' => [3, true];
        yield 'deploy' => [4, true];
        yield 'stay' => [5, true];
        yield 'spy' => [6, true];
        yield 'colonize' => [7, true];
        yield 'recycle' => [8, true];
        yield 'destroy' => [9, true];
        yield 'missile' => [10, true];
        yield 'expedition' => [15, true];
        yield 'gap in the id range is skipped' => [11, false];
        yield 'unknown id is skipped' => [99, false];
        yield 'zero is skipped' => [0, false];
    }

    /**
     * The reflective string-built dispatch is gone; every known mission id must
     * still resolve to a handler, and unknown ids must resolve to nothing
     * (the legacy code would have hit an undefined-index notice instead).
     */
    #[DataProvider('missionProvider')]
    public function testDispatcherForMapsKnownMissionsOnly(int $mission, bool $expectHandler): void
    {
        $method = new ReflectionMethod(MissionControlLib::class, 'dispatcherFor');
        $dispatch = $method->invoke(new MissionControlLib(), $mission, ['fleet_mission' => $mission]);

        $this->assertSame($expectHandler, $dispatch instanceof Closure);
    }
}
