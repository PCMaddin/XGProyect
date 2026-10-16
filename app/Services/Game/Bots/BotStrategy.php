<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\ResearchEnumerator as Research;
use App\Services\Game\Formulas\DevelopmentsService;

/**
 * What a bot builds next: a plain build order like a careful new player's.
 * Energy first, then a full storage, the facilities that lag behind the
 * mines, then the mines (crystal two, deuterium four levels behind metal);
 * of these the most urgent one it can pay now, so it does not sit on its
 * stock waiting for a scarce resource. Research follows a short opening
 * list and then always takes the cheapest useful step. Ships and defences
 * are {@see BotShipyard}'s.
 *
 * Only decides; checks the game's own rules (requirements, price) through
 * DevelopmentsService so a bot never gets what a player could not.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class BotStrategy
{
    /** research in this order up to these levels, then the cheapest step */
    private const OPENING_RESEARCH = [
        [Research::RESEARCH_ENERGY_TECHNOLOGY, 1],
        [Research::RESEARCH_COMBUSTION_DRIVE, 2],
        [Research::RESEARCH_ESPIONAGE_TECHNOLOGY, 2],
        [Research::RESEARCH_COMPUTER_TECHNOLOGY, 2],
        [Research::RESEARCH_ENERGY_TECHNOLOGY, 3],
        [Research::RESEARCH_COMBUSTION_DRIVE, 3],
        [Research::RESEARCH_LASER_TECHNOLOGY, 3],
        [Research::RESEARCH_IMPULSE_DRIVE, 3],
        [Research::RESEARCH_ESPIONAGE_TECHNOLOGY, 4],
        [Research::RESEARCH_ASTROPHYSICS, 1],
        [Research::RESEARCH_WEAPONS_TECHNOLOGY, 2],
        [Research::RESEARCH_SHIELDING_TECHNOLOGY, 2],
        [Research::RESEARCH_ARMOUR_TECHNOLOGY, 2],
        [Research::RESEARCH_ASTROPHYSICS, 3],
        [Research::RESEARCH_LASER_TECHNOLOGY, 6],
        [Research::RESEARCH_IONIC_TECHNOLOGY, 2],
    ];

    /** what the cheapest step is picked from after the opening */
    private const ONGOING_RESEARCH = [
        Research::RESEARCH_ENERGY_TECHNOLOGY,
        Research::RESEARCH_COMPUTER_TECHNOLOGY,
        Research::RESEARCH_ESPIONAGE_TECHNOLOGY,
        Research::RESEARCH_WEAPONS_TECHNOLOGY,
        Research::RESEARCH_SHIELDING_TECHNOLOGY,
        Research::RESEARCH_ARMOUR_TECHNOLOGY,
        Research::RESEARCH_LASER_TECHNOLOGY,
        Research::RESEARCH_IONIC_TECHNOLOGY,
        Research::RESEARCH_PLASMA_TECHNOLOGY,
        Research::RESEARCH_COMBUSTION_DRIVE,
        Research::RESEARCH_IMPULSE_DRIVE,
        Research::RESEARCH_HYPERSPACE_TECHNOLOGY,
        Research::RESEARCH_ASTROPHYSICS,
    ];

    private const STORAGES = [
        'metal' => Buildings::BUILDING_METAL_STORE,
        'crystal' => Buildings::BUILDING_CRYSTAL_STORE,
        'deuterium' => Buildings::BUILDING_DEUTERIUM_TANK,
    ];

    private const PRODUCERS = [
        'metal' => Buildings::BUILDING_METAL_MINE,
        'crystal' => Buildings::BUILDING_CRYSTAL_MINE,
        'deuterium' => Buildings::BUILDING_DEUTERIUM_SINTETIZER,
    ];

    public function __construct(private DevelopmentsService $developments)
    {
    }

    /**
     * The next building for the planet, or null if none of the plan is
     * allowed. $planet holds the stock, storage capacity and energy.
     *
     * @param array<int, int>             $levels   levels by object id
     * @param array<string, float|int>    $planet   planet_metal, planet_metal_max, planet_metal_perhour, …, planet_energy_max, planet_energy_used
     */
    public function nextBuilding(array $levels, array $planet, bool $hasLab, bool $researching): ?int
    {
        $first = null;

        foreach ($this->buildingPlan($levels, $planet, $hasLab, $researching) as $building) {
            if (!$this->developments->isDevelopmentAllowed($building, $levels)) {
                continue;
            }

            $building = $this->producerFor($building, $levels, $planet)
                ?? $this->storageFor($building, $levels, $planet)
                ?? $building;

            // the most urgent step the bot can pay now; if none, it saves for the most urgent
            if ($this->isPayable($building, $levels, $planet)) {
                return $building;
            }

            $first ??= $building;
        }

        return $first;
    }

    /**
     * The next research, or null if nothing on the list is allowed yet.
     *
     * @param array<int, int> $levels
     */
    public function nextResearch(array $levels): ?int
    {
        foreach (self::OPENING_RESEARCH as [$tech, $target]) {
            if (($levels[$tech] ?? 0) < $target && $this->developments->isDevelopmentAllowed($tech, $levels)) {
                return $tech;
            }
        }

        $cheapest = null;
        $cheapestCost = PHP_FLOAT_MAX;

        foreach (self::ONGOING_RESEARCH as $tech) {
            if (!$this->developments->isDevelopmentAllowed($tech, $levels)) {
                continue;
            }

            $cost = array_sum($this->developments->developmentPrice($tech, $levels[$tech] ?? 0));

            if ($cost < $cheapestCost) {
                $cheapest = $tech;
                $cheapestCost = $cost;
            }
        }

        return $cheapest;
    }

    /**
     * The plan in order of urgency.
     *
     * @param array<int, int>          $levels
     * @param array<string, float|int> $planet
     *
     * @return list<int>
     */
    private function buildingPlan(array $levels, array $planet, bool $hasLab, bool $researching): array
    {
        $metal = $levels[Buildings::BUILDING_METAL_MINE] ?? 0;
        $energyMax = (float) ($planet['planet_energy_max'] ?? 0);
        $energy = $energyMax + (float) ($planet['planet_energy_used'] ?? 0);

        // short of energy, the mines run slower: nothing but the power plant,
        // even if the bot has to save for it
        if ($energy < 0) {
            return [Buildings::BUILDING_SOLAR_PLANT];
        }

        return array_merge(
            $energy < 15 + $energyMax * 0.1 ? [Buildings::BUILDING_SOLAR_PLANT] : [],
            $this->fullStorages($planet),
            $this->laggingFacilities($levels, $metal, $hasLab && !$researching),
            $this->mines($levels, $metal)
        );
    }

    /**
     * The storages that are at least 90 % full.
     *
     * @param array<string, float|int> $planet
     *
     * @return list<int>
     */
    private function fullStorages(array $planet): array
    {
        $full = [];

        foreach (self::STORAGES as $resource => $storage) {
            $capacity = (float) ($planet['planet_' . $resource . '_max'] ?? 0);

            if ($capacity > 0 && (float) ($planet['planet_' . $resource] ?? 0) >= $capacity * 0.9) {
                $full[] = $storage;
            }
        }

        return $full;
    }

    /**
     * The facilities below their target, which grows with the metal mine.
     *
     * @param array<int, int> $levels
     *
     * @return list<int>
     */
    private function laggingFacilities(array $levels, int $metal, bool $labAllowed): array
    {
        $targets = [
            Buildings::BUILDING_ROBOT_FACTORY => min(10, intdiv($metal, 3)),
            Buildings::BUILDING_HANGAR => min(12, intdiv($metal, 3)),
            Buildings::BUILDING_LABORATORY => $labAllowed ? min(12, intdiv($metal, 2) - 1) : 0,
            Buildings::BUILDING_NANO_FACTORY => max(0, intdiv($metal - 15, 5)),
        ];
        $lagging = [];

        foreach ($targets as $facility => $target) {
            if (($levels[$facility] ?? 0) < $target) {
                $lagging[] = $facility;
            }
        }

        return $lagging;
    }

    /**
     * Crystal two and deuterium four levels behind metal, then metal.
     *
     * @param array<int, int> $levels
     *
     * @return list<int>
     */
    private function mines(array $levels, int $metal): array
    {
        if (($levels[Buildings::BUILDING_CRYSTAL_MINE] ?? 0) < $metal - 2) {
            return [Buildings::BUILDING_CRYSTAL_MINE, Buildings::BUILDING_METAL_MINE];
        }

        if (($levels[Buildings::BUILDING_DEUTERIUM_SINTETIZER] ?? 0) < $metal - 4) {
            return [Buildings::BUILDING_DEUTERIUM_SINTETIZER, Buildings::BUILDING_METAL_MINE];
        }

        return [Buildings::BUILDING_METAL_MINE];
    }

    /**
     * The mine to build first when the building needs a resource the planet
     * lacks and does not produce (a robotics factory needs deuterium), so
     * the bot does not wait for it forever.
     *
     * @param array<int, int>          $levels
     * @param array<string, float|int> $planet
     */
    private function producerFor(int $building, array $levels, array $planet): ?int
    {
        $price = $this->developments->developmentPrice($building, $levels[$building] ?? 0);

        foreach (self::PRODUCERS as $resource => $producer) {
            if (($price[$resource] ?? 0) > (float) ($planet['planet_' . $resource] ?? 0) &&
                (float) ($planet['planet_' . $resource . '_perhour'] ?? 0) <= 0 &&
                $producer !== $building) {
                return $producer;
            }
        }

        return null;
    }

    /**
     * The storage to build first when the building costs more than the
     * planet can hold.
     *
     * @param array<int, int>          $levels
     * @param array<string, float|int> $planet
     */
    private function storageFor(int $building, array $levels, array $planet): ?int
    {
        $price = $this->developments->developmentPrice($building, $levels[$building] ?? 0);

        foreach (self::STORAGES as $resource => $storage) {
            $capacity = (float) ($planet['planet_' . $resource . '_max'] ?? 0);

            if ($capacity > 0 && ($price[$resource] ?? 0) > $capacity && $storage !== $building) {
                return $storage;
            }
        }

        return null;
    }

    /**
     * @param array<int, int>          $levels
     * @param array<string, float|int> $planet
     */
    private function isPayable(int $building, array $levels, array $planet): bool
    {
        foreach ($this->developments->developmentPrice($building, $levels[$building] ?? 0) as $resource => $amount) {
            if (isset(self::PRODUCERS[$resource]) && $amount > (float) ($planet['planet_' . $resource] ?? 0)) {
                return false;
            }
        }

        return true;
    }
}
