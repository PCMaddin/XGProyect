<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\GameObjects\Building;
use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\ProductionFormula;
use App\Core\GameObjects\Ship;
use App\Services\FormatService;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\Game\Formulas\ProductionService;
use App\Services\SettingsService;
use App\Support\Concerns\ReadsMixedValues;

/**
 * The level tables of the technology details page: what a mine, power
 * plant or storage yields, how far the phalanx reaches and how many
 * colonies and expeditions astrophysics allows, around the current level.
 *
 * @phpstan-type ProductionInputs array{levelFactor: int, temperature: int, energyTech: int, multiplier: int, geologistBoost: float|int, engineerBoost: float|int}
 */
class TechnologyTableService
{
    use ReadsMixedValues;

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    private int $elementId = 0;

    /** @var ProductionInputs */
    private array $productionInputs = [
        'levelFactor' => 0,
        'temperature' => 0,
        'energyTech' => 0,
        'multiplier' => 0,
        'geologistBoost' => 1,
        'engineerBoost' => 1,
    ];

    public function __construct(
        private GameObjectRegistry $registry,
        private ProductionService $productionService,
        private FormatService $formatService,
        private OfficerService $officerService,
        private FleetsService $fleetsService,
        private SettingsService $settings,
    ) {
    }

    /**
     * The level table of the technology details: production, storage,
     * range or astrophysics by level; null for the other objects.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     *
     * @return array<string, mixed>|null
     */
    public function table(int $elementId, array $user, array $planet): ?array
    {
        $this->elementId = $elementId;
        $this->user = $user;
        $this->planet = $planet;

        if ($this->elementId >= 1 && $this->elementId <= 3) {
            return [
                'headers' => [
                    __('game/global.level'),
                    __('game/technologydetails.in_prod_p_hour'),
                    __('game/technologydetails.in_difference'),
                    __('game/technologydetails.in_lvl_difference'),
                    __('game/technologydetails.in_energy_balance'),
                    __('game/technologydetails.in_difference'),
                ],
                'rows' => $this->buildProductionRows(false),
                'footerRows' => [],
            ];
        }

        if ($this->elementId === 4) {
            return [
                'headers' => [
                    __('game/global.level'),
                    __('game/technologydetails.in_energy_balance'),
                    __('game/technologydetails.in_difference'),
                    __('game/technologydetails.in_lvl_difference'),
                ],
                'rows' => $this->buildProductionRows(true),
                'footerRows' => [],
            ];
        }

        if ($this->elementId >= 22 && $this->elementId <= 24) {
            return [
                'headers' => [
                    __('game/global.level'),
                    __('game/technologydetails.in_storage_capacity'),
                    __('game/technologydetails.in_difference'),
                ],
                'rows' => $this->buildStorageRows(),
                'footerRows' => [],
            ];
        }

        if ($this->elementId === 12) {
            return [
                'headers' => [
                    __('game/global.level'),
                    __('game/technologydetails.in_energy_balance'),
                    __('game/technologydetails.in_difference'),
                    __('game/technologydetails.in_lvl_difference'),
                    __('game/technologydetails.in_used_deuter'),
                    __('game/technologydetails.in_difference'),
                ],
                'rows' => $this->buildProductionRows(false),
                'footerRows' => [],
            ];
        }

        if ($this->elementId === 42) {
            return [
                'headers' => [
                    __('game/global.level'),
                    __('game/technologydetails.in_range'),
                ],
                'rows' => $this->buildRangeRows(),
                'footerRows' => [],
            ];
        }

        if ($this->elementId === 124) {
            return [
                'headers' => [
                    __('game/global.level'),
                    __('game/technologydetails.in_max_colonies'),
                    __('game/technologydetails.in_max_expeditions'),
                ],
                'rows' => $this->buildAstrophysicsRows(),
                'footerRows' => [
                    __('game/technologydetails.in_astrophysics_first'),
                    __('game/technologydetails.in_astrophysics_second'),
                    __('game/technologydetails.in_astrophysics_third'),
                ],
            ];
        }

        return null;
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function buildStorageRows(): array
    {
        $currentBuiltLevel = self::asInt($this->planet[$this->registry->get($this->elementId)->getName()] ?? 0);
        $buildStartLevel = max(1, $currentBuiltLevel - 2);
        $factor = PlayerClassService::bonusesFromRow($this->user)->storageFactor();
        $actualProduction = $this->productionService->maxStorable($currentBuiltLevel, $factor);
        $rows = [];

        for ($buildLevel = $buildStartLevel; $buildLevel < $buildStartLevel + 15; ++$buildLevel) {
            $production = $this->productionService->maxStorable($buildLevel, $factor);

            $rows[] = [
                $currentBuiltLevel === $buildLevel ? '<font color="#ff0000">' . $buildLevel . '</font>' : (string) $buildLevel,
                $this->formatService->prettyNumber((int) $production),
                $this->formatService->colorNumber($production - $actualProduction, $this->formatService->prettyNumber($production - $actualProduction)),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function buildAstrophysicsRows(): array
    {
        $currentBuiltLevel = self::asInt($this->user[$this->registry->get($this->elementId)->getName()] ?? 0);
        $buildStartLevel = max(1, $currentBuiltLevel - 2);
        $rows = [];

        for ($buildLevel = $buildStartLevel; $buildLevel < $buildStartLevel + 15; ++$buildLevel) {
            $rows[] = [
                $currentBuiltLevel === $buildLevel ? '<font color="#ff0000">' . $buildLevel . '</font>' : (string) $buildLevel,
                $this->formatService->prettyNumber((int) $this->fleetsService->getMaxColonies($buildLevel)),
                $this->formatService->prettyNumber((int) $this->fleetsService->getMaxExpeditions($buildLevel)),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    private function buildRangeRows(): array
    {
        $currentBuiltLevel = self::asInt($this->planet[$this->registry->get($this->elementId)->getName()] ?? 0);
        $buildStartLevel = max(1, $currentBuiltLevel - 2);
        $rows = [];

        for ($buildLevel = $buildStartLevel; $buildLevel < $buildStartLevel + 15; $buildLevel++) {
            $rows[] = [
                $currentBuiltLevel === $buildLevel ? '<font color="#ff0000">' . $buildLevel . '</font>' : (string) $buildLevel,
                (string) (($buildLevel * $buildLevel) - 1),
            ];
        }

        return $rows;
    }

    /**
     * Production by level; the power plants show the energy, the fusion
     * reactor also its deuterium use, the mines also the energy (unless
     * $simple).
     *
     * @return array<int, array<int, string|int>>
     */
    private function buildProductionRows(bool $simple): array
    {
        $resourceKey = $this->registry->get($this->elementId)->getName();
        $currentBuiltLevel = self::asInt($this->planet[$resourceKey] ?? 0);
        $this->productionInputs = $this->productionInputs($resourceKey);

        $baseline = $this->productionValues($currentBuiltLevel > 0 ? $currentBuiltLevel : 1);
        $own = $this->ownProductionIndex();
        $actualProduction = floor($baseline[$own]);
        $actualNeed = $this->elementId !== 12 ? floor($baseline[4]) : floor($baseline[3]);
        $buildStartLevel = max(1, $currentBuiltLevel - 2);
        $firstProduction = 0;
        $rows = [];

        for ($buildLevel = $buildStartLevel; $buildLevel < $buildStartLevel + 15; $buildLevel++) {
            $production = $this->productionValues($buildLevel);
            $productionDiff = $currentBuiltLevel === 0
                ? $production[$this->elementId >= 4 ? 4 : 3]
                : floor($production[$own] - $actualProduction);

            $row = [
                $currentBuiltLevel === $buildLevel ? $this->formatService->colorRed((string) $buildLevel) : (string) $buildLevel,
                $this->formatService->prettyNumber(floor($production[$own])),
                $this->formatService->colorNumber($productionDiff, $this->formatService->prettyNumber((int) $productionDiff)),
                $this->formatService->colorGreen((string) $this->levelDiff($production, $firstProduction, $currentBuiltLevel)),
            ];

            if ($this->elementId === 12) {
                $row = [...$row, ...$this->deuteriumUseColumns($production, $actualNeed, $currentBuiltLevel)];
            } elseif (!$simple) {
                $row[] = $this->formatService->colorNumber(floor($production[4]), $this->formatService->prettyNumber(floor($production[4])));
                $row[] = $this->formatService->colorNumber(floor($production[4] - $actualNeed), $this->formatService->prettyNumber(floor($production[4] - $actualNeed)));
            }

            $rows[] = $row;
            $firstProduction = floor($production[$own]);
        }

        return $rows;
    }

    /**
     * The inputs of the production formulas that do not depend on the level.
     *
     * @return ProductionInputs
     */
    private function productionInputs(string $resourceKey): array
    {
        $geologist = $this->officerService->isOfficerActive(self::asInt($this->user['premium_officier_geologist'] ?? 0), time());
        $engineer = $this->officerService->isOfficerActive(self::asInt($this->user['premium_officier_engineer'] ?? 0), time());

        return [
            'levelFactor' => self::asInt($this->planet['planet_' . $resourceKey . '_percent'] ?? 0),
            'temperature' => self::asInt($this->planet['planet_temp_max'] ?? 0),
            'energyTech' => self::asInt($this->user['research_energy_technology'] ?? 0),
            'multiplier' => $this->settings->getInt('resource_multiplier'),
            'geologistBoost' => 1 + (1 * ($geologist ? GEOLOGUE : 0)),
            'engineerBoost' => 1 + (1 * ($engineer ? ENGINEER_ENERGY : 0)),
        ];
    }

    /**
     * The production gained over the previous row; on the first row 0, or
     * for a building not built yet the production of level 1.
     *
     * @param array{1: float|int, 2: float|int, 3: float|int, 4: float|int} $production
     */
    private function levelDiff(array $production, float | int $previous, int $currentBuiltLevel): string | int | float
    {
        if ($previous > 0) {
            return $this->formatService->prettyNumber(floor($production[$this->ownProductionIndex()] - $previous));
        }

        if ($currentBuiltLevel === 0) {
            return $this->elementId >= 4 ? $production[4] : $production[3];
        }

        return 0;
    }

    /**
     * The fusion reactor's deuterium use and its difference to the current level.
     *
     * @param array{1: float|int, 2: float|int, 3: float|int, 4: float|int} $production
     *
     * @return list<string>
     */
    private function deuteriumUseColumns(array $production, float $actualNeed, int $currentBuiltLevel): array
    {
        $needDiff = $currentBuiltLevel === 0 ? $production[3] : floor($production[3] - $actualNeed);

        return [
            $this->formatService->colorNumber(floor($production[3]), $this->formatService->prettyNumber(floor($production[3]))),
            $this->formatService->colorNumber($needDiff, $this->formatService->prettyNumber((int) $needDiff)),
        ];
    }

    /**
     * The production row index of this element: its own resource for the
     * mines (1-3), the energy (4) for the power plants.
     *
     * @return 1|2|3|4
     */
    private function ownProductionIndex(): int
    {
        return match ($this->elementId) {
            1 => 1,
            2 => 2,
            3 => 3,
            default => 4,
        };
    }

    /**
     * @return array{1: float|int, 2: float|int, 3: float|int, 4: float|int}
     */
    private function productionValues(int $buildLevel): array
    {
        [
            'levelFactor' => $buildLevelFactor,
            'temperature' => $buildTemp,
            'energyTech' => $buildEnergy,
            'multiplier' => $resourceMultiplier,
            'geologistBoost' => $geologistBoost,
            'engineerBoost' => $engineerBoost,
        ] = $this->productionInputs;
        $formula = $this->productionFormula($this->elementId);
        $metalProduction = $formula?->calculateMetal($buildLevel, $buildLevelFactor) ?? 0.0;
        $crystalProduction = $formula?->calculateCrystal($buildLevel, $buildLevelFactor) ?? 0.0;
        $deuteriumProduction = $formula?->calculateDeuterium($buildLevel, $buildLevelFactor, $buildTemp) ?? 0.0;
        $energyProduction = $formula?->calculateEnergy($buildLevel, $buildLevelFactor, $buildTemp, $buildEnergy) ?? 0.0;

        return [
            1 => $this->productionService->productionAmount($metalProduction, $geologistBoost, $resourceMultiplier),
            2 => $this->productionService->productionAmount($crystalProduction, $geologistBoost, $resourceMultiplier),
            3 => $this->productionService->productionAmount($deuteriumProduction, $geologistBoost, $resourceMultiplier),
            4 => $this->productionService->productionAmount(
                $energyProduction,
                $this->elementId >= 4 ? $engineerBoost : 1,
                0,
                true
            ),
        ];
    }

    /**
     * The production formula of a building or ship (null for anything else).
     */
    private function productionFormula(int $elementId): ?ProductionFormula
    {
        $object = $this->registry->has($elementId) ? $this->registry->get($elementId) : null;

        return $object instanceof Building || $object instanceof Ship ? $object->getProduction() : null;
    }
}
