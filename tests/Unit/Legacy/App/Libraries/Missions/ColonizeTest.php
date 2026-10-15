<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries\Missions;

use App\Libraries\FleetsLib;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Xgp\App\Libraries\Missions\Colonize;

class ColonizeTest extends TestCase
{
    private function invoke(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(Colonize::class, $method);

        return $ref->invoke(new Colonize(), ...$args);
    }

    /**
     * Regression: buildNewFleet() was declared `: bool` but returned the
     * serialized fleet, so under strict_types every colonization that carried
     * more than the colony ship crashed with a TypeError.
     */
    public function testBuildNewFleetLeavesOneColonyShipBehind(): void
    {
        $fleet = FleetsLib::setFleetShipsArray([202 => 5, 208 => 2]);

        $result = $this->invoke('buildNewFleet', $fleet);

        $this->assertIsString($result);
        $this->assertSame([202 => 5, 208 => 1], FleetsLib::getFleetShipsArray($result));
    }

    public function testBuildNewFleetDropsTheLastColonyShipAndEmptyEntries(): void
    {
        $fleet = FleetsLib::setFleetShipsArray([208 => 1, 203 => 0, 210 => 3]);

        $result = $this->invoke('buildNewFleet', $fleet);

        $this->assertIsString($result);
        $this->assertSame([210 => 3], FleetsLib::getFleetShipsArray($result));
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: bool}>
     */
    public static function positions(): array
    {
        return [
            'inner position, no astrophysics' => [4, 0, true],
            'inner position 12' => [12, 0, true],
            'position 3 needs level 4' => [3, 3, false],
            'position 3 at level 4' => [3, 4, true],
            'position 13 at level 4' => [13, 4, true],
            'position 2 needs level 6' => [2, 5, false],
            'position 14 at level 6' => [14, 6, true],
            'position 1 needs level 8' => [1, 7, false],
            'position 15 at level 8' => [15, 8, true],
        ];
    }

    #[DataProvider('positions')]
    public function testPositionAllowed(int $position, int $level, bool $allowed): void
    {
        $this->assertSame($allowed, $this->invoke('positionAllowed', $position, $level));
    }
}
