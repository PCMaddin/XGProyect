<?php

declare(strict_types=1);

namespace App\Libraries\Game;

use App\Services\Game\PlayerClassService;
use App\Support\Concerns\HydratesModels;
use App\Services\Game\Formulas\ProductionService;
use App\Models\Buildings;
use App\Models\Planets;
use App\Models\Premium;

/**
 * Dark-matter pricing for the resource-market storage refill.
 *
 * The legacy version dispatched every per-resource lookup through dynamic
 * method names ($this->planet->{'getPlanetAmountOf'.ucfirst($r)}()); this one
 * resolves them through explicit match helpers so the types are known.
 */
class ResourceMarket
{
    use HydratesModels;

    private Premium $premium;

    private Planets $planet;

    private Buildings $buildings;

    /** traders' alliances store 10 % more */
    private float $storageFactor;

    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $planet
     */
    public function __construct(array $user, array $planet, private ProductionService $productionService)
    {
        $this->premium = self::hydrateRow(Premium::class, $user);
        $this->planet = self::hydrateRow(Planets::class, $planet);
        $this->buildings = self::hydrateRow(Buildings::class, $planet);
        $this->storageFactor = PlayerClassService::bonusesFromRow($user)->storageFactor();
    }

    public function calculateBasePriceToRefill(int $maxStorage, int $baseDm): float
    {
        // (max_storage * 0.10) * base_dark_matter / (max_initial_storage * 0.10)
        return ($maxStorage * 0.10) * $baseDm / ($this->productionService->maxStorable(0) * 0.10);
    }

    public function getPriceToFill10Percent(string $resource): float
    {
        return $this->calculateRefillStoragePrice($resource, 10);
    }

    public function getPriceToFill50Percent(string $resource): float
    {
        return $this->calculateRefillStoragePrice($resource, 50);
    }

    public function getPriceToFill100Percent(string $resource): float
    {
        return $this->calculateRefillStoragePrice($resource, 100, $this->planetAmount($resource));
    }

    public function calculateRefillStoragePrice(string $resource, int $percentage, float $currentResources = 0): float
    {
        $maxStorage = $this->productionService->maxStorable($this->storeLevel($resource), $this->storageFactor);
        $basePrice = $this->calculateBasePriceToRefill($maxStorage, $this->baseDarkMatter($resource));

        if ($maxStorage === 0) {
            return 0.0;
        }

        return floor((($maxStorage - $currentResources) * $percentage / $maxStorage) * $basePrice / 10);
    }

    public function isMetalStorageFull(): bool
    {
        return $this->isStorageFull('metal');
    }

    public function isCrystalStorageFull(): bool
    {
        return $this->isStorageFull('crystal');
    }

    public function isDeuteriumStorageFull(): bool
    {
        return $this->isStorageFull('deuterium');
    }

    public function getProjectedResouces(string $resource, int $percentage): float
    {
        $amountToFill = $this->productionService->maxStorable($this->storeLevel($resource), $this->storageFactor) * $percentage / 100;

        if ($percentage !== 100) {
            return $this->planetAmount($resource) + $amountToFill;
        }

        return $amountToFill;
    }

    public function isMetalStorageFillable(int $percentage): bool
    {
        return $this->isStorageFillable('metal', $percentage);
    }

    public function isCrystalStorageFillable(int $percentage): bool
    {
        return $this->isStorageFillable('crystal', $percentage);
    }

    public function isDeuteriumStorageFillable(int $percentage): bool
    {
        return $this->isStorageFillable('deuterium', $percentage);
    }

    public function isRefillPayable(string $resource, int $percentage): bool
    {
        return $this->premium->premium_dark_matter >= $this->calculateRefillStoragePrice(
            $resource,
            $percentage,
            $percentage === 100 ? $this->planetAmount($resource) : 0
        );
    }

    /**
     * How much more of the resource fits into the planet's storage.
     */
    public function freeStorage(string $resource): float
    {
        return $this->productionService->maxStorable($this->storeLevel($resource), $this->storageFactor) - $this->planetAmount($resource);
    }

    private function isStorageFillable(string $resource, int $percentage): bool
    {
        if ($this->isStorageFull($resource)) {
            return false;
        }

        return $this->productionService->maxStorable($this->storeLevel($resource), $this->storageFactor) >= $this->getProjectedResouces($resource, $percentage);
    }

    private function isStorageFull(string $resource): bool
    {
        return $this->productionService->maxStorable($this->storeLevel($resource), $this->storageFactor) <= $this->planetAmount($resource);
    }

    private function planetAmount(string $resource): float
    {
        return match ($resource) {
            'metal' => $this->planet->planet_metal,
            'crystal' => $this->planet->planet_crystal,
            'deuterium' => $this->planet->planet_deuterium,
            default => 0.0,
        };
    }

    private function storeLevel(string $resource): int
    {
        return match ($resource) {
            'metal' => $this->buildings->building_metal_store,
            'crystal' => $this->buildings->building_crystal_store,
            'deuterium' => $this->buildings->building_deuterium_tank,
            default => 0,
        };
    }

    private function baseDarkMatter(string $resource): int
    {
        return match ($resource) {
            'metal', 'crystal', 'deuterium' => BASIC_RESOURCE_MARKET_DM[$resource],
            default => 0,
        };
    }
}
