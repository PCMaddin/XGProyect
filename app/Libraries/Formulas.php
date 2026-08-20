<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Services\Game\Formulas\FormulasService;

/**
 * Static facade over {@see FormulasService}, kept only so the not-yet-migrated
 * legacy engine can keep calling `Formulas::method()`. Native code should
 * inject {@see FormulasService} directly instead of using this bridge.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 */
abstract class Formulas
{
    private static function service(): FormulasService
    {
        return app(FormulasService::class);
    }

    public static function phalanxRange(int $phalanxLevel): int
    {
        return self::service()->phalanxRange($phalanxLevel);
    }

    public static function missileRange(int $impulseDriveLevel): int
    {
        return self::service()->missileRange($impulseDriveLevel);
    }

    /** @return array{planet_diameter: int, planet_field_max: int} */
    public static function getPlanetSize(int $position, bool $main = false): array
    {
        return self::service()->getPlanetSize($position, $main);
    }

    public static function calculatePlanetFields(int $diameter): int
    {
        return self::service()->calculatePlanetFields($diameter);
    }

    public static function setPlanetImage(int $system, int $position): string
    {
        return self::service()->setPlanetImage($system, $position);
    }

    /** @return array{min: int, max: int} */
    public static function setPlanetTemp(int $position): array
    {
        return self::service()->setPlanetTemp($position);
    }

    public static function getMoonDestructionChance(int $planetDiameter, int $deathStars): int
    {
        return self::service()->getMoonDestructionChance($planetDiameter, $deathStars);
    }

    public static function getDeathStarsDestructionChance(int $planetDiameter): int
    {
        return self::service()->getDeathStarsDestructionChance($planetDiameter);
    }

    public static function getIonTechnologyBonus(int $ionTechnologyLevel): float
    {
        return self::service()->getIonTechnologyBonus($ionTechnologyLevel);
    }

    public static function getPlasmaTechnologyBonus(int $plasmaTechnologyLevel, string $resource): float
    {
        return self::service()->getPlasmaTechnologyBonus($plasmaTechnologyLevel, $resource);
    }

    public static function getDevelopmentCost(int $price, float $factor, int $level): float
    {
        return self::service()->getDevelopmentCost($price, $factor, $level);
    }

    public static function getTearDownBaseCost(int $price, float $factor, int $level): int
    {
        return self::service()->getTearDownBaseCost($price, $factor, $level);
    }

    public static function getTearDownCost(int $price, float $factor, int $level, int $ionTechnologyLevel): int
    {
        return self::service()->getTearDownCost($price, $factor, $level, $ionTechnologyLevel);
    }

    public static function getBuildingTime(float $metalCost, float $crystalCost, int $building, int $roboticsFactory, int $naniteFactory, int $level): float
    {
        return self::service()->getBuildingTime($metalCost, $crystalCost, $building, $roboticsFactory, $naniteFactory, $level);
    }

    public static function getShipyardProductionTime(float $metalCost, float $crystalCost, int $shipDefense, int $shipyardLevel, int $naniteFactoryLevel): float
    {
        return self::service()->getShipyardProductionTime($metalCost, $crystalCost, $shipDefense, $shipyardLevel, $naniteFactoryLevel);
    }

    public static function getResearchTime(float $metalCost, float $crystalCost, int $totalLabLevel, int $expeditionLevel): float
    {
        return self::service()->getResearchTime($metalCost, $crystalCost, $totalLabLevel, $expeditionLevel);
    }

    public static function getTearDownTime(int $metalCost, int $crystalCost, int $building, int $roboticsFactory, int $naniteFactory, int $level): float
    {
        return self::service()->getTearDownTime($metalCost, $crystalCost, $building, $roboticsFactory, $naniteFactory, $level);
    }
}
