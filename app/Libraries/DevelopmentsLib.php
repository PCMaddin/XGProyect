<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Services\FormatService;
use App\Services\Game\Formulas\OfficerService;
use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\ResearchEnumerator as Research;
use App\Core\GameObjects\Building;
use App\Core\GameObjects\Defense;
use App\Core\GameObjects\GameObject;
use App\Core\GameObjects\GameObjectInterface;
use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Research as ResearchObject;
use App\Core\GameObjects\Ship;

/**
 * Development cost / time / price helpers, shared by the native build pages and
 * the still-legacy tick engine (UpdatesLibrary). Ported from the legacy static
 * class; the untyped `array` game-state maps are read through the mixed-safe
 * converters below so the whole class is clean at level 9.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 */
class DevelopmentsLib
{
    public static function setBuildingPage(int $element): string
    {
        $supplies = [1, 2, 3, 4, 12, 22, 23, 24];
        $facilities = [14, 15, 21, 31, 33, 34, 44];

        if (in_array($element, $supplies, true)) {
            return 'supplies';
        }

        if (in_array($element, $facilities, true)) {
            return 'facilities';
        }

        // In case the element doesn't exist.
        return 'overview';
    }

    /**
     * @param array<string, mixed> $currentPlanet
     */
    public static function maxFields(array $currentPlanet): int
    {
        $terraformer = self::asInt($currentPlanet[self::objectName(33)] ?? 0);

        return self::asInt($currentPlanet['planet_field_max'] ?? 0) + ($terraformer * FIELDS_BY_TERRAFORMER);
    }

    /**
     * @param array<string, mixed> $currentUser
     * @param array<string, mixed> $currentPlanet
     *
     * @return array<string, float>
     */
    public static function developmentPrice(array $currentUser, array $currentPlanet, int $element, bool $incremental = true, bool $destroy = false): array
    {
        $resource = self::objectNames();
        $pricelist = self::priceList();
        $level = $incremental ? self::currentLevel($currentUser, $currentPlanet, $resource, $element) : 0;
        $cost = [];

        foreach (['metal', 'crystal', 'deuterium', 'energy_max'] as $type) {
            if (!isset($pricelist[$element][$type])) {
                continue;
            }

            $base = self::asInt($pricelist[$element][$type]);
            $factor = self::asFloat($pricelist[$element]['factor'] ?? 0);

            $cost[$type] = $incremental
                ? Formulas::getDevelopmentCost($base, $factor, $level)
                : floor($base);

            if ($destroy) {
                $ionTech = self::asInt($currentUser[$resource[Research::RESEARCH_IONIC_TECHNOLOGY]] ?? 0);
                $cost[$type] = (float) Formulas::getTearDownCost($base, $factor, $level, $ionTech);
            }
        }

        return $cost;
    }

    /**
     * @param array<string, mixed> $currentUser
     * @param array<string, mixed> $currentPlanet
     */
    public static function isDevelopmentPayable(array $currentUser, array $currentPlanet, int $element, bool $incremental = true, bool $destroy = false): bool
    {
        $costs = self::developmentPrice($currentUser, $currentPlanet, $element, $incremental, $destroy);

        foreach ($costs as $resource => $amount) {
            if ($amount > self::asFloat($currentPlanet['planet_' . $resource] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $currentUser
     * @param array<string, mixed> $currentPlanet
     */
    public static function formatedDevelopmentPrice(array $currentUser, array $currentPlanet, int $element, bool $userfactor = true, int | bool $level = false): string
    {
        $resource = self::objectNames();
        $pricelist = self::priceList();

        if ($userfactor && $level === false) {
            $level = self::currentLevel($currentUser, $currentPlanet, $resource, $element);
        }

        $format = app(FormatService::class);
        $text = (string) __('game/buildings.require');
        $titles = [
            'metal' => __('game/global.metal'),
            'crystal' => __('game/global.crystal'),
            'deuterium' => __('game/global.deuterium'),
            'energy_max' => __('game/global.energy'),
        ];

        foreach ($titles as $resType => $resTitle) {
            if (!isset($pricelist[$element][$resType]) || self::asInt($pricelist[$element][$resType]) === 0) {
                continue;
            }

            $cost = $userfactor
                ? Formulas::getDevelopmentCost(self::asInt($pricelist[$element][$resType]), self::asFloat($pricelist[$element]['factor'] ?? 0), (int) $level)
                : floor(self::asFloat($pricelist[$element][$resType]));
            $available = self::asFloat($currentPlanet['planet_' . $resType] ?? 0);
            $text .= $resTitle . ': ';

            if ($cost > $available) {
                $text .= '<b style="color:red;"> <t title="-' . $format->prettyNumber($cost - $available) . '">';
                $text .= '<span class="noresources">' . $format->prettyNumber((int) $cost) . '</span></t></b> ';

                continue;
            }

            $text .= '<b style="color:lime;">' . $format->prettyNumber((int) $cost) . '</b> ';
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $currentUser
     * @param array<string, mixed> $currentPlanet
     */
    public static function developmentTime(array $currentUser, array $currentPlanet, int $element, int | false $level = false, int $totalLabLevel = 0): int
    {
        $resource = self::objectNames();
        $pricelist = self::priceList();
        $object = self::registry()->has($element) ? self::registry()->get($element) : null;
        $time = 1.0;

        if ($level === false) {
            $level = self::currentLevel($currentUser, $currentPlanet, $resource, $element);
        }

        $factor = self::asFloat($pricelist[$element]['factor'] ?? 0);
        $costMetal = Formulas::getDevelopmentCost(self::asInt($pricelist[$element]['metal'] ?? 0), $factor, $level);
        $costCrystal = Formulas::getDevelopmentCost(self::asInt($pricelist[$element]['crystal'] ?? 0), $factor, $level);

        if ($object instanceof Building) {
            $time = Formulas::getBuildingTime(
                $costMetal,
                $costCrystal,
                $element,
                self::asInt($currentPlanet[$resource['14']] ?? 0),
                self::asInt($currentPlanet[$resource['15']] ?? 0),
                $level
            );
        }

        if ($object instanceof ResearchObject) {
            $time = self::researchTime($currentUser, $currentPlanet, $resource, $costMetal, $costCrystal, $totalLabLevel);
        }

        if ($object instanceof Defense || $object instanceof Ship) {
            $time = Formulas::getShipyardProductionTime(
                $costMetal,
                $costCrystal,
                $element,
                self::asInt($currentPlanet[$resource[Buildings::BUILDING_HANGAR]] ?? 0),
                self::asInt($currentPlanet[$resource[Buildings::BUILDING_NANO_FACTORY]] ?? 0)
            );
        }

        return (int) ($time < 1 ? 1 : $time);
    }

    public static function tearDownTime(int $building, int $roboticsFactory, int $naniteFactory, int $level): float
    {
        $pricelist = self::priceList();
        $factor = self::asFloat($pricelist[$building]['factor'] ?? 0);

        $metalCost = Formulas::getTearDownBaseCost(self::asInt($pricelist[$building]['metal'] ?? 0), $factor, $level);
        $crystalCost = Formulas::getTearDownBaseCost(self::asInt($pricelist[$building]['crystal'] ?? 0), $factor, $level);

        return Formulas::getTearDownTime($metalCost, $crystalCost, $building, $roboticsFactory, $naniteFactory, $level);
    }

    public static function formatedDevelopmentTime(int $time, string $prefix): string
    {
        return '<br>' . $prefix . app(FormatService::class)->prettyTime($time);
    }

    /**
     * @param array<string, mixed> $currentUser
     * @param array<string, mixed> $currentPlanet
     */
    public static function isDevelopmentAllowed(array $currentUser, array $currentPlanet, int $element): bool
    {
        $resource = self::objectNames();
        $object = self::registry()->has($element) ? self::registry()->get($element) : null;

        if (!$object instanceof GameObject) {
            return true;
        }

        foreach ($object->getRequirements() as $reqElement => $eleLevel) {
            $name = self::asString($resource[self::asInt($reqElement)] ?? '');
            $needed = self::asInt($eleLevel);

            if (self::asInt($currentUser[$name] ?? 0) < $needed && self::asInt($currentPlanet[$name] ?? 0) < $needed) {
                return false;
            }
        }

        return true;
    }

    public static function currentBuilding(string $callProgram, int $elementId = 0): string
    {
        return Template::render('buildings.build_list_script', [
            'call_program' => $callProgram,
            'current_page' => $elementId !== 0 ? self::setBuildingPage($elementId) : $callProgram,
        ]);
    }

    /**
     * @param array<string, mixed> $currentUser
     */
    public static function setLevelFormat(int $level, int $element = 0, array $currentUser = []): string
    {
        $format = app(FormatService::class);
        $officer = app(OfficerService::class);
        $return = $level !== 0 ? ' (' . __('game/global.level') . $level . ')' : '';

        if ($element === 106 && $officer->isOfficerActive(self::asInt($currentUser['premium_officier_technocrat'] ?? 0), time())) {
            $return .= $format->strongText($format->colorGreen(' +' . TECHNOCRATE_SPY . __('game/research.re_spy')));
        }

        if ($element === 108 && $officer->isOfficerActive(self::asInt($currentUser['premium_officier_admiral'] ?? 0), time())) {
            $return .= $format->strongText($format->colorGreen(' +' . AMIRAL . __('game/research.re_commander')));
        }

        return $return;
    }

    /**
     * @param array<string, mixed> $currentUser
     */
    public static function isLabWorking(array $currentUser): bool
    {
        return self::asInt($currentUser['research_current_research'] ?? 0) !== 0;
    }

    /**
     * @param array<string, mixed> $currentPlanet
     */
    public static function isShipyardWorking(array $currentPlanet): bool
    {
        return self::asInt($currentPlanet['planet_b_hangar'] ?? 0) !== 0;
    }

    /**
     * @param array<string, mixed> $currentPlanet
     */
    public static function areFieldsAvailable(array $currentPlanet): bool
    {
        return self::asInt($currentPlanet['planet_field_current'] ?? 0) < self::maxFields($currentPlanet);
    }

    /**
     * @param array<string, mixed> $currentUser
     * @param array<string, mixed> $currentPlanet
     * @param array<int|string, string> $resource
     */
    private static function researchTime(array $currentUser, array $currentPlanet, array $resource, float $costMetal, float $costCrystal, int $totalLabLevel): float
    {
        $intergalactic = self::asInt($currentUser[$resource[Research::RESEARCH_INTERGALACTIC_RESEARCH_NETWORK]] ?? 0);
        $labLevel = $intergalactic < 1
            ? self::asInt($currentPlanet[$resource[Buildings::BUILDING_LABORATORY]] ?? 0)
            : $totalLabLevel;

        $time = Formulas::getResearchTime(
            $costMetal,
            $costCrystal,
            $labLevel,
            self::asInt($currentUser[$resource[Research::RESEARCH_ASTROPHYSICS]] ?? 0)
        );

        $technocrate = app(OfficerService::class)->isOfficerActive(self::asInt($currentUser['premium_officier_technocrat'] ?? 0), time())
            ? TECHNOCRATE_SPEED
            : 0;

        return floor($time * (1 - $technocrate));
    }

    /**
     * @param array<string, mixed> $currentUser
     * @param array<string, mixed> $currentPlanet
     * @param array<int|string, string> $resource
     */
    private static function currentLevel(array $currentUser, array $currentPlanet, array $resource, int $element): int
    {
        $name = self::asString($resource[$element] ?? '');

        return self::asInt($currentPlanet[$name] ?? $currentUser[$name] ?? 0);
    }

    private static function registry(): GameObjectRegistry
    {
        return app(GameObjectRegistry::class);
    }

    private static function objectName(int $id): string
    {
        return self::objectNames()[$id] ?? '';
    }

    /**
     * @return array<int, string>
     */
    private static function objectNames(): array
    {
        return self::registry()->names();
    }

    /**
     * Base prices and factor by object id (metal, crystal, deuterium, factor,
     * energy or energy_max).
     *
     * @return array<int, array<string, int|float>>
     */
    private static function priceList(): array
    {
        return self::registry()->all()->map(fn (GameObjectInterface $object): array => $object->getPrice()->toArray())->all();
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function asFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
