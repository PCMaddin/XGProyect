<?php

declare(strict_types=1);

namespace App\Libraries\Game;

use App\Support\Concerns\HydratesModels;
use App\Models\Fleets as FleetsModel;
use App\Core\Enumerators\MissionsEnumerator as Missions;

/**
 * Wraps a user's fleet rows into typed entities and indexes them by fleet id.
 *
 * getOwnValidFleetById() now guards the not-own-fleet case (the legacy version
 * called methods on the null getOwnFleetById() returned); getFleetById() still
 * returns an empty entity for an unknown id.
 */
class Fleets
{
    use HydratesModels;

    /** @var list<FleetsModel> */
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
            $entity = self::hydrateRow(FleetsModel::class, $fleet);

            $this->fleets[] = $entity;
            $this->fleetsIndex[$this->asInt($entity->fleet_id)] = $index++;
            $this->fleetCount++;

            if ($this->asInt($entity->fleet_mission) === Missions::EXPEDITION) {
                $this->expeditionCount++;
            }
        }
    }

    /**
     * @return list<FleetsModel>
     */
    public function getFleets(): array
    {
        return $this->fleets;
    }

    public function getFleetById(int $fleetId): FleetsModel
    {
        return $this->fleets[$this->fleetsIndex[$fleetId] ?? -1] ?? self::hydrateRow(FleetsModel::class, []);
    }

    public function getOwnFleetById(int $fleetId): ?FleetsModel
    {
        $fleet = $this->getFleetById($fleetId);

        return $this->asInt($fleet->fleet_owner) === $this->currentUserId ? $fleet : null;
    }

    public function getOwnValidFleetById(int $fleetId): ?FleetsModel
    {
        $fleet = $this->getOwnFleetById($fleetId);

        if ($fleet === null) {
            return null;
        }

        if (
            $this->asInt($fleet->fleet_start_time) <= time() ||
            $this->asInt($fleet->fleet_end_time) < time() ||
            $this->asInt($fleet->fleet_mess) === 1
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
