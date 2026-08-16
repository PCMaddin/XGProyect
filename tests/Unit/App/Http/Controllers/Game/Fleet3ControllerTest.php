<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\Fleet3Controller;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\TestCase;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;

#[CoversClass(Fleet3Controller::class)]
class Fleet3ControllerTest extends TestCase
{
    public function testWithoutRemovesTheGivenMissionAndReindexes(): void
    {
        $controller = app(Fleet3Controller::class);
        $method = new ReflectionMethod(Fleet3Controller::class, 'without');

        $result = $method->invoke($controller, [Missions::ATTACK, Missions::ACS, Missions::SPY], Missions::ACS);

        $this->assertSame([Missions::ATTACK, Missions::SPY], $result);
    }

    public function testWithoutIsANoOpWhenMissionIsAbsent(): void
    {
        $controller = app(Fleet3Controller::class);
        $method = new ReflectionMethod(Fleet3Controller::class, 'without');

        $result = $method->invoke($controller, [Missions::ATTACK, Missions::SPY], Missions::COLONIZE);

        $this->assertSame([Missions::ATTACK, Missions::SPY], $result);
    }

    public function testMissionNameResolvesTheTranslationTable(): void
    {
        $controller = app(Fleet3Controller::class);
        $method = new ReflectionMethod(Fleet3Controller::class, 'missionName');

        $this->assertSame('Attack', $method->invoke($controller, Missions::ATTACK));
        $this->assertSame('', $method->invoke($controller, 999));
    }

    public function testSessionShipsDecodesTheFleet2Encoding(): void
    {
        $fleet = [202 => 5, 203 => 12];
        session(['fleet_data' => ['fleetarray' => str_rot13(base64_encode(serialize($fleet)))]]);

        $controller = app(Fleet3Controller::class);
        $method = new ReflectionMethod(Fleet3Controller::class, 'sessionShips');

        $this->assertSame($fleet, $method->invoke($controller));
    }
}
