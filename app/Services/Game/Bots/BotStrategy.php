<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\ResearchEnumerator as Research;
use App\Core\GameObjects\Building;
use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Game\Formulas\DevelopmentsService;

/**
 * What a bot builds next: a plain build order like a careful new player's.
 * Energy first (solar plant or fusion reactor, whichever gives energy more
 * cheaply), then a full storage, the facilities that lag behind the mines,
 * then the mines (crystal two, deuterium four levels behind metal);
 * of these the most urgent one it can pay now, so it does not sit on its
 * stock waiting for a scarce resource. Research follows a short opening
 * list and then takes the cheapest useful step, or astrophysics once every
 * colony slot is taken. Ships and defences
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
        [Research::RESEARCH_IMPULSE_DRIVE, 4],
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

    /** with every colony slot taken, astrophysics may cost this many times the cheapest research */
    private const ASTROPHYSICS_PRIORITY = 4;

    /** more metal than this many times the crystal in stock is a glut */
    private const METAL_GLUT = 3;

    /** the missile silo comes with this metal mine level, up to the level missiles need */
    private const SILO_FROM_METAL = 18;

    private const SILO_LEVEL = 4;

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

    public function __construct(
        private DevelopmentsService $developments,
        private GameObjectRegistry $registry,
    ) {
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
     * The next building on a moon: a lunar base whenever the fields run
     * out, a sensor phalanx up to level 3, then more lunar base.
     *
     * @param array<int, int> $levels
     */
    public function nextMoonBuilding(array $levels, int $freeFields): int
    {
        if ($freeFields > 1 && ($levels[Buildings::BUILDING_PHALANX] ?? 0) < 3 &&
            $this->developments->isDevelopmentAllowed(Buildings::BUILDING_PHALANX, $levels)) {
            return Buildings::BUILDING_PHALANX;
        }

        return Buildings::BUILDING_MONDBASIS;
    }

    /**
     * The next research, or null if nothing on the list is allowed yet.
     * Without a free colony slot, astrophysics may go first.
     *
     * @param array<int, int> $levels
     */
    public function nextResearch(array $levels, int $freeColonySlots = 1): ?int
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

        return $freeColonySlots === 0 && $this->astrophysicsWorthIt($levels, $cheapestCost) ? Research::RESEARCH_ASTROPHYSICS : $cheapest;
    }

    /**
     * With every colony slot taken, astrophysics opens the next ones: it
     * goes first while it costs at most a few times the cheapest step
     * (its price grows by 1.75 a level, so it is hardly ever the cheapest).
     *
     * @param array<int, int> $levels
     */
    private function astrophysicsWorthIt(array $levels, float $cheapestCost): bool
    {
        $astrophysics = Research::RESEARCH_ASTROPHYSICS;

        return $this->developments->isDevelopmentAllowed($astrophysics, $levels) &&
            array_sum($this->developments->developmentPrice($astrophysics, $levels[$astrophysics] ?? 0)) <= self::ASTROPHYSICS_PRIORITY * $cheapestCost;
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

        // short of energy, the mines run slower: nothing but a power plant,
        // even if the bot has to save for it
        if ($energy < 0) {
            return $this->powerPlants($levels);
        }

        return array_merge(
            $energy < 15 + $energyMax * 0.1 ? $this->powerPlants($levels) : [],
            $this->fullStorages($planet),
            $this->laggingFacilities($levels, $metal, $hasLab && !$researching),
            $this->mines($levels, $metal, $planet)
        );
    }

    /**
     * The solar plant and, once allowed, the fusion reactor, the one whose
     * next level costs less per unit of energy first.
     *
     * @param array<int, int> $levels
     *
     * @return list<int>
     */
    private function powerPlants(array $levels): array
    {
        $plants = [Buildings::BUILDING_SOLAR_PLANT];

        if ($this->developments->isDevelopmentAllowed(Buildings::BUILDING_FUSION_REACTOR, $levels)) {
            $plants[] = Buildings::BUILDING_FUSION_REACTOR;
        }

        usort($plants, fn (int $one, int $other): int => $this->pricePerEnergy($one, $levels) <=> $this->pricePerEnergy($other, $levels));

        return $plants;
    }

    /**
     * What the next level costs for each unit of energy it adds.
     *
     * @param array<int, int> $levels
     */
    private function pricePerEnergy(int $plant, array $levels): float
    {
        $level = $levels[$plant] ?? 0;
        $energyTech = $levels[Research::RESEARCH_ENERGY_TECHNOLOGY] ?? 0;
        $building = $this->registry->get($plant);
        $production = $building instanceof Building ? $building->getProduction() : null;
        $gain = $production === null ? 0.0
            : $production->calculateEnergy($level + 1, 10, 0, $energyTech) - $production->calculateEnergy($level, 10, 0, $energyTech);

        return $gain > 0 ? array_sum($this->developments->developmentPrice($plant, $level)) / $gain : PHP_FLOAT_MAX;
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
     * The step towards the missile silo a planet saves for (it never fits
     * between the mines): its next level, or first the mine or storage its
     * price needs; null if the silo is not due.
     *
     * @param array<int, int>          $levels
     * @param array<string, float|int> $planet
     */
    public function nextSiloStep(array $levels, array $planet): ?int
    {
        $silo = Buildings::BUILDING_MISSILE_SILO;

        return $this->wantsSilo($levels)
            ? $this->producerFor($silo, $levels, $planet) ?? $this->storageFor($silo, $levels, $planet) ?? $silo
            : null;
    }

    /**
     * Whether the planet is ready for its missile silo, up to the level that
     * launches interplanetary missiles: once impulse drive allows them and
     * the metal mine is far enough (see BotMissiles).
     *
     * @param array<int, int> $levels
     */
    public function wantsSilo(array $levels): bool
    {
        return ($levels[Research::RESEARCH_IMPULSE_DRIVE] ?? 0) >= 1 &&
            ($levels[Buildings::BUILDING_METAL_MINE] ?? 0) >= self::SILO_FROM_METAL &&
            ($levels[Buildings::BUILDING_MISSILE_SILO] ?? 0) < self::SILO_LEVEL &&
            $this->developments->isDevelopmentAllowed(Buildings::BUILDING_MISSILE_SILO, $levels);
    }

    /**
     * Crystal two and deuterium four levels behind metal, then metal. While
     * metal piles up (more than three times the crystal in stock) the bot
     * builds no more metal mine but the crystal mine.
     *
     * @param array<int, int>          $levels
     * @param array<string, float|int> $planet
     *
     * @return list<int>
     */
    private function mines(array $levels, int $metal, array $planet): array
    {
        $crystal = $levels[Buildings::BUILDING_CRYSTAL_MINE] ?? 0;

        if ((float) ($planet['planet_metal'] ?? 0) > self::METAL_GLUT * (float) ($planet['planet_crystal'] ?? 0) && $crystal <= $metal) {
            return [Buildings::BUILDING_CRYSTAL_MINE];
        }

        if ($crystal < $metal - 2) {
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
