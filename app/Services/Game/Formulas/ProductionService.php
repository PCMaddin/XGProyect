<?php

declare(strict_types=1);

namespace App\Services\Game\Formulas;

/**
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 */
class ProductionService
{
    /**
     * The capacity of a storage building; $factor is the alliance class
     * bonus (traders store 10 % more).
     */
    public function maxStorable(int $storageLevel, float $factor = 1.0): int
    {
        return (int) floor((int) (2.5 * pow(M_E, (20 * $storageLevel / 33))) * 5000 * $factor);
    }

    public function maxProductionPercentage(int $maxEnergy, int $energyUsed): int
    {
        if ($maxEnergy == 0 && $energyUsed > 0) {
            return 0;
        }

        if ($maxEnergy > 0 && ($energyUsed + $maxEnergy) < 0) {
            $percentage = (int) floor($maxEnergy / ($energyUsed * -1) * 100);

            return min($percentage, 100);
        }

        return 100;
    }

    public function productionAmount(float $production, float $boost, float $multiplier = 0.0, bool $isEnergy = false): float
    {
        if ($isEnergy) {
            return ceil($production * $boost);
        }

        return floor($production * $multiplier * $boost);
    }

    public function currentProduction(float $resource, int $maxProductionPercentage): float
    {
        return $resource * 0.01 * $maxProductionPercentage;
    }
}
