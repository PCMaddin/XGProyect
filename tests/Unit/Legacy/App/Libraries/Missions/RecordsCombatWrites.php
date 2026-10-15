<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries\Missions;

/**
 * Replaces the database access of a combat mission: the player technologies
 * come from a fixed formula and every write is recorded instead of executed.
 */
trait RecordsCombatWrites
{
    /** @var list<list<mixed>> */
    private array $calls = [];

    /**
     * @return list<list<mixed>>
     */
    public function recordedCalls(): array
    {
        return $this->calls;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTechnologiesByUserId(int $userId): array
    {
        return [
            'name' => 'player' . $userId,
            'research_weapons_technology' => $userId % 4,
            'research_shielding_technology' => ($userId + 1) % 4,
            'research_armour_technology' => ($userId + 2) % 4,
            'research_hyperspace_technology' => $userId % 3,
        ];
    }

    protected function updateReturningFleetData(array $data): void
    {
        $this->calls[] = ['updateReturningFleetData', $data];
    }

    protected function updatePlanetLossesById(array $data): void
    {
        $this->calls[] = ['updatePlanetLossesById', $data];
    }

    protected function deleteMultipleFleetsByIds(string $idString): void
    {
        $this->calls[] = ['deleteMultipleFleetsByIds', $idString];
    }

    protected function updateLostShipsAndDefensePoints(int $playerId, array $lost): void
    {
        $this->calls[] = ['updateLostShipsAndDefensePoints', $playerId, $lost];
    }

    protected function updatePlanetDebrisByCoords(array $data): void
    {
        $data['time'] = 'T';
        $this->calls[] = ['updatePlanetDebrisByCoords', $data];
    }
}
