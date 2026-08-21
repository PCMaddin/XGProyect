<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\Game\Formulas\ProductionService;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator;
use Xgp\App\Core\Objects;
use App\Services\Game\Formulas\FormulasService;
use App\Libraries\Functions;
use Xgp\App\Libraries\Users;

/**
 * Resource settings: production overview and per-mine production percentages.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class ResourcesettingsController extends BaseController
{
    use PreparesLegacySql;

    private const REDIRECT_TARGET = 'game.php?page=resourcesettings';

    /** Storage building object ids (metal / crystal / deuterium tank). */
    private const STORAGE_METAL = 22;
    private const STORAGE_CRYSTAL = 23;
    private const STORAGE_DEUTERIUM = 24;

    private const VALID_PERCENTAGES = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    /** @var array<int, string> */
    private array $resource = [];

    /** @var array<int, mixed> */
    private array $prodGrid = [];

    /** @var array<string, mixed> */
    private array $reslist = [];

    public function __construct(
        private ProductionService $productionService,
        private FormatService $formatService,
        private OfficerService $officerService,
        private FormulasService $formulasService,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::ResourceSettings));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();

        $objects = Objects::getInstance();
        $this->resource = $this->toStringMap($objects->getObjects());
        $this->prodGrid = is_array($grid = $objects->getProduction()) ? $grid : [];
        $this->reslist = is_array($list = $objects->getObjectsList()) ? $list : [];

        if ($request->isMethod('post') && !Users::getInstance()->isOnVacations($this->user)) {
            return $this->savePercentages($request);
        }

        return view('resourcesettings.view', $this->buildPage());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPage(): array
    {
        $settings = app(SettingsService::class);
        $multiplier = $settings->getInt('resource_multiplier');

        $basicIncome = $this->basicIncome($settings);

        $storageMetalMax = $this->productionService->maxStorable($this->planetInt($this->resource[self::STORAGE_METAL] ?? ''));
        $storageCrystalMax = $this->productionService->maxStorable($this->planetInt($this->resource[self::STORAGE_CRYSTAL] ?? ''));
        $storageDeuteriumMax = $this->productionService->maxStorable($this->planetInt($this->resource[self::STORAGE_DEUTERIUM] ?? ''));

        // The current production percentage is based on the planet's stored
        // energy balance before the per-building recomputation below.
        $postPercent = $this->productionService->maxProductionPercentage(
            $this->planetInt('planet_energy_max'),
            $this->planetInt('planet_energy_used')
        );

        $buildTemp = $this->planetInt('planet_temp_max');
        $energyTech = $this->userInt('research_energy_technology');
        $geologeBoost = 1.0 + ($this->officerService->isOfficerActive($this->userInt('premium_officier_geologist'), time()) ? GEOLOGUE : 0);
        $engineerBoost = 1.0 + ($this->officerService->isOfficerActive($this->userInt('premium_officier_engineer'), time()) ? ENGINEER_ENERGY : 0);
        $plasmaLevel = $this->userInt('research_plasma_technology');

        $production = $this->accumulateProduction($multiplier, $buildTemp, $energyTech, $geologeBoost, $engineerBoost, $plasmaLevel, $postPercent);
        $productionLevel = $this->prodLevel($production['energyUsed'], $production['energyMax']);

        return array_merge(
            [
                'Production_of_resources_in_the_planet' => str_replace(
                    '%s',
                    $this->planetStr('planet_name'),
                    (string) __('game/resources.rs_production_on_planet')
                ),
                'resource_row' => $production['resourceRow'],
                'metal_basic_income' => $basicIncome['metal'],
                'crystal_basic_income' => $basicIncome['crystal'],
                'deuterium_basic_income' => $basicIncome['deuterium'],
                'energy_basic_income' => $basicIncome['energy'],
                'plasma_level' => $plasmaLevel,
                'plasma_metal' => $this->coloredNumber($production['plasmaBoost']['metal']),
                'plasma_crystal' => $this->coloredNumber($production['plasmaBoost']['crystal']),
                'plasma_deuterium' => $this->coloredNumber($production['plasmaBoost']['deuterium']),
                'planet_metal_max' => $this->resourceColor($this->planetInt('planet_metal'), $storageMetalMax),
                'planet_crystal_max' => $this->resourceColor($this->planetInt('planet_crystal'), $storageCrystalMax),
                'planet_deuterium_max' => $this->resourceColor($this->planetInt('planet_deuterium'), $storageDeuteriumMax),
            ],
            $this->totals(
                $production['metalPerHour'],
                $production['crystalPerHour'],
                $production['deuteriumPerHour'],
                $production['energyMax'],
                $production['energyUsed'],
                $productionLevel,
                $basicIncome
            ),
        );
    }

    /**
     * Accumulates the per-building production grid into the totals the view needs.
     *
     * @return array{
     *     metalPerHour: float, crystalPerHour: float, deuteriumPerHour: float,
     *     energyMax: float, energyUsed: float,
     *     plasmaBoost: array{metal: float, crystal: float, deuterium: float},
     *     resourceRow: string
     * }
     */
    private function accumulateProduction(
        int $multiplier,
        int $buildTemp,
        int $energyTech,
        float $geologeBoost,
        float $engineerBoost,
        int $plasmaLevel,
        int $postPercent,
    ): array {
        $metalPerHour = 0.0;
        $crystalPerHour = 0.0;
        $deuteriumPerHour = 0.0;
        $energyMax = 0.0;
        $energyUsed = 0.0;
        $plasmaBoost = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];
        $resourceRow = '';

        foreach ($this->prodIds() as $prodId) {
            $name = $this->resource[$prodId] ?? '';

            if ($name === '' || $this->planetInt($name) <= 0 || !isset($this->prodGrid[$prodId])) {
                continue;
            }

            $level = $this->planetInt($name);
            $factor = $this->planetInt('planet_' . $name . '_percent');

            $metalProd = $this->runFormula($prodId, 'metal', $level, $factor, $buildTemp, $energyTech);
            $crystalProd = $this->runFormula($prodId, 'crystal', $level, $factor, $buildTemp, $energyTech);
            $deuteriumProd = $this->runFormula($prodId, 'deuterium', $level, $factor, $buildTemp, $energyTech);
            $energyProd = $this->runFormula($prodId, 'energy', $level, $factor, $buildTemp, $energyTech);

            $plasmaMetal = $this->productionService->productionAmount($metalProd, $this->formulasService->getPlasmaTechnologyBonus($plasmaLevel, 'metal'), $multiplier);
            $plasmaCrystal = $this->productionService->productionAmount($crystalProd, $this->formulasService->getPlasmaTechnologyBonus($plasmaLevel, 'crystal'), $multiplier);
            $plasmaDeuterium = $this->productionService->productionAmount($deuteriumProd, $this->formulasService->getPlasmaTechnologyBonus($plasmaLevel, 'deuterium'), $multiplier);

            $plasmaBoost['metal'] += $plasmaMetal;
            $plasmaBoost['crystal'] += $plasmaCrystal;
            $plasmaBoost['deuterium'] += $plasmaDeuterium;

            $metalPerHour += $this->productionService->productionAmount($metalProd, $geologeBoost, $multiplier) + $plasmaMetal;
            $crystalPerHour += $this->productionService->productionAmount($crystalProd, $geologeBoost, $multiplier) + $plasmaCrystal;
            $deuteriumPerHour += $this->productionService->productionAmount($deuteriumProd, $geologeBoost, $multiplier) + $plasmaDeuterium;

            $energy = $this->productionService->productionAmount($energyProd, $prodId >= 4 ? $engineerBoost : 1.0, 0, true);
            $energyMax += $energy > 0 ? $energy : 0.0;
            $energyUsed += $energy > 0 ? 0.0 : $energy;

            $resourceRow .= $this->renderRow($prodId, $name, $factor, $postPercent, [
                'metal' => $metalProd,
                'crystal' => $crystalProd,
                'deuterium' => $deuteriumProd,
                'energy' => $energy,
            ]);
        }

        return [
            'metalPerHour' => $metalPerHour,
            'crystalPerHour' => $crystalPerHour,
            'deuteriumPerHour' => $deuteriumPerHour,
            'energyMax' => $energyMax,
            'energyUsed' => $energyUsed,
            'plasmaBoost' => $plasmaBoost,
            'resourceRow' => $resourceRow,
        ];
    }

    /**
     * @param  array<string, int>  $basicIncome
     *
     * @return array<string, string>
     */
    private function totals(
        float $metalPerHour,
        float $crystalPerHour,
        float $deuteriumPerHour,
        float $energyMax,
        float $energyUsed,
        int $productionLevel,
        array $basicIncome,
    ): array {
        $metalTotal = floor(($metalPerHour * 0.01 * $productionLevel) + $basicIncome['metal']);
        $crystalTotal = floor(($crystalPerHour * 0.01 * $productionLevel) + $basicIncome['crystal']);
        $deuteriumTotal = floor(($deuteriumPerHour * 0.01 * $productionLevel) + $basicIncome['deuterium']);
        $energyTotal = floor(($energyMax + $basicIncome['energy']) + $energyUsed);

        return [
            'metal_total' => $this->coloredNumber($metalTotal),
            'crystal_total' => $this->coloredNumber($crystalTotal),
            'deuterium_total' => $this->coloredNumber($deuteriumTotal),
            'energy_total' => $this->coloredNumber($energyTotal),
            'daily_metal' => $this->coloredNumber($this->calculateDaily($metalPerHour, $productionLevel, $basicIncome['metal'])),
            'weekly_metal' => $this->coloredNumber($this->calculateWeekly($metalPerHour, $productionLevel, $basicIncome['metal'])),
            'daily_crystal' => $this->coloredNumber($this->calculateDaily($crystalPerHour, $productionLevel, $basicIncome['crystal'])),
            'weekly_crystal' => $this->coloredNumber($this->calculateWeekly($crystalPerHour, $productionLevel, $basicIncome['crystal'])),
            'daily_deuterium' => $this->coloredNumber($this->calculateDaily($deuteriumPerHour, $productionLevel, $basicIncome['deuterium'])),
            'weekly_deuterium' => $this->coloredNumber($this->calculateWeekly($deuteriumPerHour, $productionLevel, $basicIncome['deuterium'])),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function basicIncome(SettingsService $settings): array
    {
        $onMoonOrVacation = $this->userInt('preference_vacation_mode') > 0
            || $this->planetInt('planet_type') === PlanetTypesEnumerator::MOON;

        return [
            'metal' => $onMoonOrVacation ? 0 : $settings->getInt('metal_basic_income'),
            'crystal' => $onMoonOrVacation ? 0 : $settings->getInt('crystal_basic_income'),
            'deuterium' => $onMoonOrVacation ? 0 : $settings->getInt('deuterium_basic_income'),
            'energy' => $settings->getInt('energy_basic_income'),
        ];
    }

    /**
     * @param  array<string, float>  $production
     */
    private function renderRow(int $prodId, string $name, int $factor, int $postPercent, array $production): string
    {
        $metal = $this->productionService->currentProduction($production['metal'], $postPercent);
        $crystal = $this->productionService->currentProduction($production['crystal'], $postPercent);
        $deuterium = $this->productionService->currentProduction($production['deuterium'], $postPercent);
        $energy = $this->productionService->currentProduction($production['energy'], $postPercent);

        return view('resourcesettings.resources_row', [
            'name' => $name,
            'percent' => $factor,
            'option' => $this->buildPercentageOptions($factor),
            'type' => $this->setLangLine($name),
            'level' => $prodId > 200 ? __('game/resources.rs_amount') : __('game/global.level'),
            'level_type' => $this->planetInt($name),
            'metal_type' => $this->coloredNumber($metal),
            'crystal_type' => $this->coloredNumber($crystal),
            'deuterium_type' => $this->coloredNumber($deuterium),
            'energy_type' => $this->coloredNumber($energy),
        ])->render();
    }

    private function savePercentages(Request $request): RedirectResponse
    {
        $sets = [];
        $bindings = [];

        /** @var array<string, mixed> $payload */
        $payload = $request->post();

        foreach ($payload as $field => $value) {
            $column = 'planet_' . $field . '_percent';

            if (!array_key_exists($column, $this->planet)) {
                continue;
            }

            $numeric = is_numeric($value) ? (int) $value : -1;

            if (!in_array($numeric, self::VALID_PERCENTAGES, true)) {
                return redirect(self::REDIRECT_TARGET);
            }

            // The column name is whitelisted against the planet's real columns.
            $sets[] = '`' . $column . '` = ?';
            $bindings[] = intdiv($numeric, 10);
        }

        if ($sets !== []) {
            $bindings[] = $this->planetInt('planet_id');

            DB::update(
                $this->prepareSql(
                    'UPDATE `' . PLANETS . '` SET ' . implode(', ', $sets) . ' WHERE `planet_id` = ?;'
                ),
                $bindings
            );
        }

        return redirect(self::REDIRECT_TARGET);
    }

    private function runFormula(int $prodId, string $resource, int $level, int $factor, int $temp, int $energyTech): float
    {
        $entry = $this->prodGrid[$prodId] ?? null;

        if (!is_array($entry) || !isset($entry['formule']) || !is_array($entry['formule'])) {
            return 0.0;
        }

        $formula = $entry['formule'][$resource] ?? null;

        if (!is_callable($formula)) {
            return 0.0;
        }

        $result = $formula($level, $factor, $temp, $energyTech);

        return is_numeric($result) ? (float) $result : 0.0;
    }

    private function buildPercentageOptions(int $currentPercentage): string
    {
        $options = '';

        for ($option = 10; $option >= 0; $option--) {
            $value = $option * 10;
            $selected = $option === $currentPercentage ? ' selected=selected' : '';
            $options .= '<option value="' . $value . '"' . $selected . '>' . $value . '%</option>';
        }

        return $options;
    }

    private function calculateDaily(float $perHour, int $productionLevel, int $basicIncome): float
    {
        return floor(($basicIncome + ($perHour * 0.01 * $productionLevel)) * 24);
    }

    private function calculateWeekly(float $perHour, int $productionLevel, int $basicIncome): float
    {
        return floor(($basicIncome + ($perHour * 0.01 * $productionLevel)) * 24 * 7);
    }

    private function resourceColor(int $current, int $max): string
    {
        $formatted = $this->formatService->prettyNumber($max / 1000) . 'k';

        return $max < $current
            ? $this->formatService->colorRed($formatted)
            : $this->formatService->colorGreen($formatted);
    }

    private function prodLevel(float $energyUsed, float $energyMax): int
    {
        $level = 100;

        if ($energyMax > 0 && abs($energyUsed) > $energyMax) {
            $level = (int) floor($energyMax / ($energyUsed * -1) * 100);
        }

        if ($energyMax === 0.0 && $energyUsed !== 0.0) {
            $level = 0;
        }

        return min($level, 100);
    }

    private function setLangLine(string $langLine): string
    {
        $map = [
            'building_' => 'constructions',
            'research_' => 'technologies',
            'ship_' => 'ships',
            'defense_' => 'defenses',
        ];

        $prefix = '';

        foreach ($map as $needle => $lang) {
            if (str_contains($langLine, $needle)) {
                $prefix = $lang;
                break;
            }
        }

        return (string) __('game/' . $prefix . '.' . $langLine);
    }

    private function coloredNumber(float $value): string
    {
        $rounded = (int) $value;

        return $this->formatService->colorNumber($rounded, $this->formatService->prettyNumber($rounded));
    }

    /**
     * @return array<int, int>
     */
    private function prodIds(): array
    {
        $prod = $this->reslist['prod'] ?? null;

        if (!is_array($prod)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $prod));
    }

    /**
     * @param  mixed  $objects
     *
     * @return array<int, string>
     */
    private function toStringMap(mixed $objects): array
    {
        if (!is_array($objects)) {
            return [];
        }

        $map = [];

        foreach ($objects as $id => $name) {
            if (is_numeric($id) && is_scalar($name)) {
                $map[(int) $id] = (string) $name;
            }
        }

        return $map;
    }

    private function planetInt(string $key): int
    {
        $value = $this->planet[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function planetStr(string $key): string
    {
        $value = $this->planet[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function userInt(string $key): int
    {
        $value = $this->user[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
