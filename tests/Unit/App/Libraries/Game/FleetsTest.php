<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Game;

use App\Libraries\Game\Fleets;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Xgp\App\Core\Entity\FleetEntity;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;

#[CoversClass(Fleets::class)]
class FleetsTest extends TestCase
{
    private const OWNER = 10;

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        $future = time() + 1000;

        return [
            // own, outbound, still valid to recall
            ['fleet_id' => 1, 'fleet_owner' => self::OWNER, 'fleet_mission' => Missions::TRANSPORT, 'fleet_start_time' => $future, 'fleet_end_time' => $future * 2, 'fleet_mess' => 0],
            // own expedition
            ['fleet_id' => 2, 'fleet_owner' => self::OWNER, 'fleet_mission' => Missions::EXPEDITION, 'fleet_start_time' => $future, 'fleet_end_time' => $future * 2, 'fleet_mess' => 0],
            // someone else's fleet
            ['fleet_id' => 3, 'fleet_owner' => 99, 'fleet_mission' => Missions::TRANSPORT, 'fleet_start_time' => $future, 'fleet_end_time' => $future * 2, 'fleet_mess' => 0],
        ];
    }

    public function testCountsFleetsAndExpeditions(): void
    {
        $fleets = new Fleets($this->rows(), self::OWNER);

        $this->assertSame(3, $fleets->getFleetsCount());
        $this->assertSame(1, $fleets->getExpeditionsCount());
    }

    public function testGetFleetByIdResolvesThroughTheIndex(): void
    {
        $fleets = new Fleets($this->rows(), self::OWNER);

        $this->assertSame(2, $fleets->getFleetById(2)->getFleetId());
        // unknown id -> a (blank) FleetEntity rather than null
        $this->assertInstanceOf(FleetEntity::class, $fleets->getFleetById(999));
    }

    public function testGetOwnFleetByIdRejectsOtherPlayersFleets(): void
    {
        $fleets = new Fleets($this->rows(), self::OWNER);

        $this->assertNotNull($fleets->getOwnFleetById(1));
        $this->assertNull($fleets->getOwnFleetById(3));
    }

    public function testGetOwnValidFleetByIdReturnsRecallableOwnFleet(): void
    {
        $fleets = new Fleets($this->rows(), self::OWNER);

        $this->assertSame(1, $fleets->getOwnValidFleetById(1)?->getFleetId());
    }

    public function testGetOwnValidFleetByIdGuardsAgainstOtherPlayersFleet(): void
    {
        $fleets = new Fleets($this->rows(), self::OWNER);

        // legacy called methods on the null a non-owned fleet produced here
        $this->assertNull($fleets->getOwnValidFleetById(3));
    }
}
