<?php

declare(strict_types=1);

namespace App\Services\Game\Formulas;

use App\Support\ClassBonuses;
use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Ship;
use App\Enums\Game\DriveType;

/**
 * @SuppressWarnings("PHPMD.UnusedLocalVariable")
 */
class FleetsService
{
    public function __construct(
        private GameObjectRegistry $registry,
    ) {
    }

    public function targetDistance(int $origGalaxy, int $destGalaxy, int $origSystem, int $destSystem, int $origPlanet, int $destPlanet): int
    {
        if (($origGalaxy - $destGalaxy) != 0) {
            return abs($origGalaxy - $destGalaxy) * 20000;
        }

        if (($origSystem - $destSystem) != 0) {
            return abs($origSystem - $destSystem) * 5 * 19 + 2700;
        }

        if (($origPlanet - $destPlanet) != 0) {
            return abs($origPlanet - $destPlanet) * 5 + 1000;
        }

        return 5;
    }

    public function missionDuration(int $percentage, float $maxFleetSpeed, int $distance, int $speedFactor): float
    {
        return (35000 / $percentage * sqrt($distance * 10 / $maxFleetSpeed) + 10) / $speedFactor;
    }

    /**
     * Calculate the effective speed of each ship in a fleet, factoring in drive upgrades.
     *
     * @param array<int, int> $fleet        Ship ID => count
     * @param int             $combustion   Combustion drive level
     * @param int             $impulse      Impulse drive level
     * @param int             $hyperspace   Hyperspace drive level
     *
     * @return array<int, float> Ship ID => effective speed
     */
    public function fleetMaxSpeed(array $fleet, int $combustion, int $impulse, int $hyperspace, ClassBonuses $bonuses = new ClassBonuses()): array
    {
        $speeds = [];

        foreach ($fleet as $shipId => $count) {
            $speeds[$shipId] = $this->getShipSpeed($shipId, $combustion, $impulse, $hyperspace, $bonuses);
        }

        return $speeds;
    }

    /**
     * The speed of a ship with the drives of the player; collectors and
     * generals fly some ships twice as fast (PlayerClass::speedFactor).
     */
    public function getShipSpeed(int $shipId, int $combustion, int $impulse, int $hyperspace, ClassBonuses $bonuses = new ClassBonuses()): float
    {
        if ($shipId === 0) {
            return 0.0;
        }

        /** @var Ship $ship */
        $ship = $this->registry->get($shipId);
        $drive = $ship->getDrive();

        $secondaryTech = $drive->getSecondary() !== null
            ? $this->techLevelFor($drive->getSecondary(), $combustion, $impulse, $hyperspace)
            : 0;
        $tertiaryTech = $drive->getTertiary() !== null
            ? $this->techLevelFor($drive->getTertiary(), $combustion, $impulse, $hyperspace)
            : 0;

        $activeDrive = $drive->getActiveDrive($secondaryTech, $tertiaryTech);
        $baseSpeed = $drive->usesUpgradedSpeed($secondaryTech, $tertiaryTech)
            ? $ship->getSpeed2()
            : $ship->getSpeed();

        $techLevel = $this->techLevelFor($activeDrive, $combustion, $impulse, $hyperspace);

        return ($baseSpeed + ($baseSpeed * $techLevel * $activeDrive->speedBonus())) * $bonuses->speedFactor($shipId);
    }

    /**
     * The deuterium a ship uses; generals use a quarter less.
     */
    public function shipConsumption(int $shipId, int $combustion, int $impulse, int $hyperspace, ClassBonuses $bonuses = new ClassBonuses()): int
    {
        /** @var Ship $ship */
        $ship = $this->registry->get($shipId);
        $drive = $ship->getDrive();

        $secondaryTech = $drive->getSecondary() !== null
            ? $this->techLevelFor($drive->getSecondary(), $combustion, $impulse, $hyperspace)
            : 0;
        $tertiaryTech = $drive->getTertiary() !== null
            ? $this->techLevelFor($drive->getTertiary(), $combustion, $impulse, $hyperspace)
            : 0;

        $consumption = $drive->usesUpgradedSpeed($secondaryTech, $tertiaryTech) ? $ship->getConsumption2() : $ship->getConsumption();

        return (int) round($consumption * $bonuses->consumptionFactor());
    }

    private function techLevelFor(DriveType $drive, int $combustion, int $impulse, int $hyperspace): int
    {
        return match ($drive) {
            DriveType::Combustion => $combustion,
            DriveType::Impulse => $impulse,
            DriveType::Hyperspace => $hyperspace,
            DriveType::None => 0,
        };
    }

    /**
     * @param array<int, int> $fleetArray Ship ID => count
     */
    public function fleetConsumption(array $fleetArray, int $speedFactor, int $missionDuration, int $missionDistance, int $combustion, int $impulse, int $hyperspace, ClassBonuses $bonuses = new ClassBonuses()): float
    {
        $consumption = 0;

        foreach ($fleetArray as $shipId => $count) {
            if ($shipId > 0) {
                $shipSpeed = $this->getShipSpeed($shipId, $combustion, $impulse, $hyperspace, $bonuses);
                $shipCons = $this->shipConsumption($shipId, $combustion, $impulse, $hyperspace, $bonuses);
                $spd = 35000 / ($missionDuration * $speedFactor - 10) * sqrt($missionDistance * 10 / $shipSpeed);

                $basicConsumption = $spd + $count * $shipCons * pow((($spd / 10) + 1), 2);
                $consumption += $basicConsumption * $missionDistance / 35000 + 1;
            }
        }

        return round($consumption);
    }

    public function getMaxFleets(int $computerTech, bool $admiralActive, ClassBonuses $bonuses = new ClassBonuses()): int
    {
        return 1 + $computerTech + ($admiralActive ? AMIRAL : 0) + $bonuses->extraFleetSlots();
    }

    public function getMaxExpeditions(int $astrophysicsTech, ClassBonuses $bonuses = new ClassBonuses()): int
    {
        return (int) floor(sqrt($astrophysicsTech)) + $bonuses->extraExpeditions();
    }

    public function getMaxColonies(int $astrophysicsTech): int
    {
        return (int) ceil($astrophysicsTech / 2);
    }

    /**
     * The cargo of a ship: +5 % per hyperspace technology level, and the
     * class bonus of the ship (PlayerClass::cargoBonus).
     */
    public function getMaxStorage(int $shipStorage, int $hyperspaceTechLevel, int $shipId = 0, ClassBonuses $bonuses = new ClassBonuses()): int
    {
        return intval($shipStorage + ($shipStorage * (0.05 * $hyperspaceTechLevel + $bonuses->cargoBonus($shipId))));
    }

    public function isFleetReturning(string | int $fleetMess): bool
    {
        return ($fleetMess == 1);
    }

    /**
     * Whether the fleet carries any metal, crystal or deuterium.
     *
     * @param array<string, mixed> $fleet a fleet row
     */
    public function carriesResources(array $fleet): bool
    {
        foreach (['fleet_resource_metal', 'fleet_resource_crystal', 'fleet_resource_deuterium'] as $resource) {
            if (is_numeric($fleet[$resource] ?? null) && (float) $fleet[$resource] !== 0.0) {
                return true;
            }
        }

        return false;
    }
}
