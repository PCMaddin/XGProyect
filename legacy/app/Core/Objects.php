<?php

declare(strict_types=1);

namespace Xgp\App\Core;

use App\Core\GameObjects\Building;
use App\Core\GameObjects\Defense;
use App\Core\GameObjects\GameObject;
use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Officer;
use App\Core\GameObjects\Ship;

/**
 * @deprecated Use GameObjectRegistry instead
 *
 * @phpstan-type Formula \Closure(int|float|string, int|float|string, int|float|string=, int|float|string=): float
 * @phpstan-type ProductionSpec array{metal: int, crystal: int, deuterium: int, energy: int, factor: float, formule: array{metal: Formula, crystal: Formula, deuterium: Formula, energy: Formula}}
 * @phpstan-type CombatSpec array{shield: float, attack: float, sd?: array<int, int>}
 */
class Objects
{
    private static ?Objects $instance = null;
    /** @var array<int, string> */
    private array $objects = [];
    /** @var array<int, array<int, int>> */
    private array $relations = [];
    /** @var array<int, array<string, int|float|string>> */
    private array $price = [];
    /** @var array<int, CombatSpec> */
    private array $combatSpecs = [];
    /** @var array<int, ProductionSpec> */
    private array $production = [];
    /** @var array<string, list<int>> */
    private array $objectsList = [];
    private GameObjectRegistry $registry;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new Objects();
        }

        return self::$instance;
    }

    public function __construct()
    {
        $this->registry = new GameObjectRegistry();

        $this->buildNames();
        $this->buildRelations();
        $this->buildPrices();
        $this->buildCombatSpecs();
        $this->buildObjectsList();
        $this->buildProduction();
    }

    public function registry(): GameObjectRegistry
    {
        return $this->registry;
    }

    /**
     * @return ($objectId is int ? string : array<int, string>)
     */
    public function getObjects(?int $objectId = null): array | string
    {
        return empty($objectId) ? $this->objects : $this->objects[$objectId];
    }

    /**
     * @return ($objectId is int ? array<int, int> : array<int, array<int, int>>)
     */
    public function getRelations(?int $objectId = null): array
    {
        return empty($objectId) ? $this->relations : $this->relations[$objectId];
    }

    /**
     * @return ($objectId is int ? ($resource is non-empty-string ? int|float|string : array<string, int|float|string>) : array<int, array<string, int|float|string>>)
     */
    public function getPrice(?int $objectId = null, string $resource = ''): array | int | float | string
    {
        if (empty($objectId)) {
            return $this->price;
        }

        return empty($resource) ? $this->price[$objectId] : $this->price[$objectId][$resource];
    }

    /**
     * @return ($objectId is int ? ($type is non-empty-string ? float|array<int, int> : CombatSpec) : array<int, CombatSpec>)
     */
    public function getCombatSpecs(?int $objectId = null, string $type = ''): array | float
    {
        if (empty($objectId)) {
            return $this->combatSpecs;
        }

        return empty($type) ? $this->combatSpecs[$objectId] : $this->combatSpecs[$objectId][$type];
    }

    /**
     * @return ($objectId is int ? ProductionSpec : array<int, ProductionSpec>)
     */
    public function getProduction(?int $objectId = null): array
    {
        return empty($objectId) ? $this->production : $this->production[$objectId];
    }

    /**
     * @return ($objectId is non-empty-string ? list<int> : array<string, list<int>>)
     */
    public function getObjectsList(?string $objectId = null): array
    {
        return empty($objectId) ? $this->objectsList : $this->objectsList[$objectId];
    }

    private function buildNames(): void
    {
        foreach ($this->registry->all() as $id => $obj) {
            $this->objects[$id] = $obj->getName();
        }
    }

    private function buildRelations(): void
    {
        foreach ($this->registry->all() as $id => $obj) {
            if ($obj instanceof GameObject && $obj->getRequirements()->isNotEmpty()) {
                $this->relations[$id] = $obj->getRequirements()->all();
            }
        }
    }

    private function buildPrices(): void
    {
        foreach ($this->registry->all() as $id => $obj) {
            if ($obj instanceof Officer) {
                $this->price[$id] = [
                    'darkmatter_week' => $obj->getDarkmatterWeek(),
                    'darkmatter_month' => $obj->getDarkmatterMonth(),
                    'img_big' => $obj->getImgBig(),
                    'img_small' => $obj->getImgSmall(),
                ];
                continue;
            }

            if (!$obj instanceof GameObject) {
                continue;
            }

            $this->price[$id] = $obj->getPrice()->toArray();

            if ($obj instanceof Ship) {
                $this->price[$id]['consumption'] = $obj->getConsumption();
                $this->price[$id]['consumption2'] = $obj->getConsumption2();
                $this->price[$id]['speed'] = $obj->getSpeed();
                $this->price[$id]['speed2'] = $obj->getSpeed2();
                $this->price[$id]['capacity'] = $obj->getCapacity();
            }
        }
    }

    private function buildCombatSpecs(): void
    {
        foreach ($this->registry->ships() as $id => $ship) {
            $this->combatSpecs[$id] = [
                'shield' => $ship->getShield(),
                'attack' => $ship->getAttack(),
                'sd' => $ship->getRapidFire()->all(),
            ];
        }

        foreach ($this->registry->defenses() as $id => $defense) {
            $this->combatSpecs[$id] = [
                'shield' => $defense->getShield(),
                'attack' => $defense->getAttack(),
            ];

            if ($defense->getRapidFire()->isNotEmpty()) {
                $this->combatSpecs[$id]['sd'] = $defense->getRapidFire()->all();
            }
        }
    }

    private function buildObjectsList(): void
    {
        $resourceIds = $this->registry->resourceBuildings()->keys()->all();
        $facilityIds = $this->registry->facilityBuildings()->keys()->all();
        $moonIds = $this->registry->moonBuildings()->keys()->all();
        $defenseIds = $this->registry->defenses()
            ->filter(fn (Defense $d) => $d->getId() < 500)
            ->keys()->all();
        $missileIds = $this->registry->defenses()
            ->filter(fn (Defense $d) => $d->getId() >= 500)
            ->keys()->all();
        $allBuildingIds = $this->registry->buildings()->keys()->all();
        $techIds = $this->registry->research()->keys()->all();
        $fleetIds = $this->registry->ships()->keys()->all();
        $allDefenseIds = $this->registry->defenses()->keys()->all();
        $officierIds = $this->registry->officers()->keys()->all();
        $prodIds = $this->registry->producers()->keys()->all();

        $this->objectsList = array_map(array_values(...), [
            'resources' => $resourceIds,
            'facilities' => array_merge($facilityIds, $moonIds),
            'defenses' => $defenseIds,
            'missiles' => $missileIds,
            'build' => $allBuildingIds,
            'tech' => $techIds,
            'fleet' => $fleetIds,
            'defense' => $allDefenseIds,
            'officier' => $officierIds,
            'prod' => $prodIds,
        ]);
    }

    private function buildProduction(): void
    {
        foreach ($this->registry->producers() as $id => $obj) {
            $production = null;

            if ($obj instanceof Building) {
                $production = $obj->getProduction();
            } elseif ($obj instanceof Ship) {
                $production = $obj->getProduction();
            }

            if ($production === null) {
                continue;
            }

            $this->production[$id] = [
                'metal' => $production->getBaseMetal(),
                'crystal' => $production->getBaseCrystal(),
                'deuterium' => $production->getBaseDeuterium(),
                'energy' => 0,
                'factor' => $production->getFactor(),
                'formule' => [
                    'metal' => fn (int | float | string $buildLevel, int | float | string $buildLevelFactor, int | float | string $buildTemp = 0, int | float | string $buildEnergy = 0): float
                        => $production->calculateMetal((int) $buildLevel, (float) $buildLevelFactor),
                    'crystal' => fn (int | float | string $buildLevel, int | float | string $buildLevelFactor, int | float | string $buildTemp = 0, int | float | string $buildEnergy = 0): float
                        => $production->calculateCrystal((int) $buildLevel, (float) $buildLevelFactor),
                    'deuterium' => fn (int | float | string $buildLevel, int | float | string $buildLevelFactor, int | float | string $buildTemp = 0, int | float | string $buildEnergy = 0): float
                        => $production->calculateDeuterium((int) $buildLevel, (float) $buildLevelFactor, (float) $buildTemp),
                    'energy' => fn (int | float | string $buildLevel, int | float | string $buildLevelFactor, int | float | string $buildTemp = 0, int | float | string $buildEnergy = 0): float
                        => $production->calculateEnergy((int) $buildLevel, (float) $buildLevelFactor, (float) $buildTemp, (int) $buildEnergy),
                ],
            ];
        }
    }
}
