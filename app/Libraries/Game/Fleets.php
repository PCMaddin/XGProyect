<?php

declare(strict_types=1);

namespace App\Libraries\Game;

use Xgp\App\Core\Entity\FleetEntity;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;

/**
 * Wraps a user's fleet rows into typed entities and indexes them by fleet id.
 *
 * getOwnValidFleetById() now guards the not-own-fleet case (the legacy version
 * called methods on the null getOwnFleetById() returned); getFleetById() still
 * returns an empty entity for an unknown id.
 */
class Fleets
{
    /** @var list<FleetEntity> */
    private array $fleets = [];

    /** @var array<int, int> */
    private array $fleetsIndex = [];

    private int $fleetCount = 0;

    private int $expeditionCount = 0;

    /**
     * @param  array<int, array<string, mixed>>  $fleets
     */
    public function __construct(array $fleets, private int $currentUserId)
    {
        $index = 0;

        foreach ($fleets as $fleet) {
            $entity = new FleetEntity($fleet);

            $this->fleets[] = $entity;
            $this->fleetsIndex[$this->asInt($entity->getFleetId())] = $index++;
            $this->fleetCount++;

            if ($this->asInt($entity->getFleetMission()) === Missions::EXPEDITION) {
                $this->expeditionCount++;
            }
        }
    }

    /**
     * @return list<FleetEntity>
     */
    public function getFleets(): array
    {
        return $this->fleets;
    }

    public function getFleetById(int $fleetId): FleetEntity
    {
        return $this->fleets[$this->fleetsIndex[$fleetId] ?? -1] ?? new FleetEntity([]);
    }

    public function getOwnFleetById(int $fleetId): ?FleetEntity
    {
        $fleet = $this->getFleetById($fleetId);

        return $this->asInt($fleet->getFleetOwner()) === $this->currentUserId ? $fleet : null;
    }

    public function getOwnValidFleetById(int $fleetId): ?FleetEntity
    {
        $fleet = $this->getOwnFleetById($fleetId);

        if ($fleet === null) {
            return null;
        }

        if (
            $this->asInt($fleet->getFleetStartTime()) <= time()
            || $this->asInt($fleet->getFleetEndTime()) < time()
            || $this->asInt($fleet->getFleetMess()) === 1
        ) {
            return null;
        }

        return $fleet;
    }

    public function getFleetsCount(): int
    {
        return $this->fleetCount;
    }

    public function getExpeditionsCount(): int
    {
        return $this->expeditionCount;
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
