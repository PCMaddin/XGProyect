<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\Formulas\OfficerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator as PlanetTypes;
use Xgp\App\Core\Objects;
use App\Libraries\Functions;
use App\Libraries\Premium\Premium;
use App\Libraries\Research\Researches;
use Xgp\App\Libraries\Users;
use App\Libraries\Users\Shortcuts;

/**
 * Fleet wizard step 2: pick the target coordinates (and speed) for the ships
 * selected in step 1. Redirects back to step 1 if no ships were submitted.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class Fleet2Controller extends BaseController
{
    use PreparesLegacySql;

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    private Researches $research;

    private Premium $premium;

    private Objects $objects;

    /** @var array{fleet_array: array<int, int>, fleet_list: string, amount: int, speed_all: array<int, float>} */
    private array $fleetData = [
        'fleet_array' => [],
        'fleet_list' => '',
        'amount' => 0,
        'speed_all' => [],
    ];

    public function __construct(
        private FormatService $formatService,
        private OfficerService $officerService,
        private FleetsService $fleetsService,
    ) {
    }

    public function __invoke(Request $request): View|RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Fleet));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();
        $this->objects = new Objects();

        $this->research = new Researches([$this->user]);
        $this->premium = new Premium([$this->user]);

        return $this->buildPage($request);
    }

    private function buildPage(Request $request): View|RedirectResponse
    {
        $fleetBlock = $this->buildFleetBlock($request);

        if ($request->request->count() === 0 || $this->fleetData['speed_all'] === []) {
            return redirect('game.php?page=fleet1');
        }

        session([
            'fleet_data' => [
                'fleet_speed' => min($this->fleetData['speed_all']),
                'fleetarray' => str_rot13(base64_encode(serialize($this->fleetData['fleet_array']))),
            ],
        ]);

        return view('fleet.fleet2_view', array_merge(
            [
                'fleet_block' => $fleetBlock,
                'planet_types' => $this->buildPlanetTypesBlock($request),
                'shortcuts' => $this->buildShortcutsBlock(),
                'colonies' => $this->buildColoniesBlock(),
                'acs' => $this->buildAcsBlock(),
            ],
            $this->buildInputVars($request)
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFleetBlock(Request $request): array
    {
        $objects = $this->objects->getObjects();
        $planetId = $this->planetInt('planet_id');

        $shipsRow = $planetId > 0 ? DB::selectOne(
            $this->prepareSql(
                'SELECT
                    s.`ship_small_cargo_ship`, s.`ship_big_cargo_ship`, s.`ship_light_fighter`,
                    s.`ship_heavy_fighter`, s.`ship_cruiser`, s.`ship_battleship`, s.`ship_colony_ship`,
                    s.`ship_recycler`, s.`ship_espionage_probe`, s.`ship_bomber`, s.`ship_solar_satellite`,
                    s.`ship_destroyer`, s.`ship_deathstar`, s.`ship_reaper`
                FROM `' . SHIPS . '` AS s
                WHERE s.`ship_planet_id` = ?;'
            ),
            [$planetId]
        ) : null;

        if (!is_object($shipsRow)) {
            return [];
        }

        $list = [];

        foreach (get_object_vars($shipsRow) as $shipName => $rawAvailable) {
            $available = $this->asInt($rawAvailable);

            if ($available === 0 || !is_string($shipName)) {
                continue;
            }

            $shipId = is_array($objects) ? $this->asInt(array_search($shipName, $objects, true)) : 0;
            $requested = $this->asInt($request->post('ship' . $shipId));

            if ($requested <= 0) {
                continue;
            }

            $amount = min($requested, $available);
            $speed = $this->fleetsService->getShipSpeed($shipId, $this->driveLevel('combustion'), $this->driveLevel('impulse'), $this->driveLevel('hyperspace'));

            $this->fleetData['fleet_array'][$shipId] = $amount;
            $this->fleetData['fleet_list'] .= $shipId . ',' . $amount . ';';
            $this->fleetData['amount'] += $amount;
            $this->fleetData['speed_all'][$shipId] = $speed;

            $list[] = [
                'ship_id' => $shipId,
                'consumption' => $this->fleetsService->shipConsumption($shipId, $this->driveLevel('combustion'), $this->driveLevel('impulse'), $this->driveLevel('hyperspace')),
                'speed' => $speed,
                'capacity' => $this->fleetsService->getMaxStorage(
                    $this->asInt($this->objects->getPrice($shipId, 'capacity')),
                    $this->research->getCurrentResearch()->getResearchHyperspaceTechnology()
                ),
                'ship' => $amount,
            ];
        }

        return $list;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPlanetTypesBlock(Request $request): array
    {
        if ($request->request->count() === 0) {
            return [];
        }

        $selected = $request->integer('planet_type');
        $types = [
            'fl_planet' => PlanetTypes::PLANET,
            'fl_debris' => PlanetTypes::DEBRIS,
            'fl_moon' => PlanetTypes::MOON,
        ];

        $options = [];

        foreach ($types as $label => $value) {
            $options[] = [
                'value' => $value,
                'selected' => $value === $selected ? 'selected' : '',
                'title' => __('game/fleet.' . $label),
            ];
        }

        return $options;
    }

    private function buildShortcutsBlock(): string
    {
        if (!$this->officerService->isOfficerActive($this->premium->getCurrentPremium()->getPremiumOfficierCommander(), time())) {
            return '';
        }

        $shortcuts = (new Shortcuts($this->userStr('fleet_shortcuts')))->getAllAsArray();
        $rows = [];

        foreach ($shortcuts as $shortcut) {
            $rows[] = [
                'value' => $this->coord($shortcut, 'g') . ';' . $this->coord($shortcut, 's') . ';' . $this->coord($shortcut, 'p') . ';' . $this->coord($shortcut, 'pt'),
                'selected' => '',
                'title' => $this->asString($shortcut['name']) . ' ' . $this->formatService->prettyCoords(
                    $this->coord($shortcut, 'g'),
                    $this->coord($shortcut, 's'),
                    $this->coord($shortcut, 'p')
                ) . ' ' . $this->planetTypeShort($this->coord($shortcut, 'pt')),
            ];
        }

        $shortcutRow = $rows === []
            ? view('fleet.fleet2_shortcuts_noshortcuts_row', ['shorcut_message' => __('game/fleet.fl_no_shortcuts')])->render()
            : view('fleet.fleet2_shortcuts_row', ['select' => 'shortcuts', 'options' => $rows])->render();

        return view('fleet.fleet2_shortcuts', ['shortcuts_rows' => $shortcutRow])->render();
    }

    private function buildColoniesBlock(): string
    {
        $userId = $this->userInt('id');

        $planets = $userId > 0 ? array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql(
                    'SELECT p.`planet_id`, p.`planet_name`, p.`planet_galaxy`, p.`planet_system`,
                        p.`planet_planet`, p.`planet_type`
                    FROM `' . PLANETS . '` AS p
                    WHERE p.`planet_user_id` = ?;'
                ),
                [$userId]
            )
        ) : [];

        if ($planets === []) {
            return view('fleet.fleet2_shortcuts_noshortcuts_row', ['shorcut_message' => __('game/fleet.fl_no_colony')])->render();
        }

        $options = [];

        foreach ($planets as $planet) {
            $galaxy = $this->coord($planet, 'planet_galaxy');
            $system = $this->coord($planet, 'planet_system');
            $position = $this->coord($planet, 'planet_planet');
            $type = $this->coord($planet, 'planet_type');

            $options[] = [
                'value' => $galaxy . ';' . $system . ';' . $position . ';' . $type,
                'selected' => '',
                'title' => $this->asString($planet['planet_name'] ?? '') . ' ' . $this->formatService->prettyCoords($galaxy, $system, $position)
                    . ($type === PlanetTypes::MOON ? ' (' . __('game/global.moon') . ')' : ''),
            ];
        }

        return view('fleet.fleet2_shortcuts_row', ['select' => 'colonies', 'options' => $options])->render();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildAcsBlock(): array
    {
        $userId = $this->userInt('id');

        $currentAcs = array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql(
                    'SELECT acs.*
                    FROM `' . ACS_MEMBERS . '` am
                    INNER JOIN `' . ACS . '` acs ON acs.`acs_id` = am.`acs_group_id`
                    INNER JOIN `' . FLEETS . '` f ON f.`fleet_group` = acs.`acs_id`
                    WHERE am.`acs_user_id` = ?;'
                ),
                [$userId]
            )
        );

        $fleets = [];

        foreach ($currentAcs as $acs) {
            $fleets[] = [
                'galaxy' => $acs['acs_galaxy'] ?? 0,
                'system' => $acs['acs_system'] ?? 0,
                'planet' => $acs['acs_planet'] ?? 0,
                'planet_type' => $acs['acs_planet_type'] ?? 0,
                'id' => $acs['acs_id'] ?? 0,
                'name' => $acs['acs_name'] ?? '',
            ];
        }

        return $fleets;
    }

    /**
     * @return array<string, int>
     */
    private function buildInputVars(Request $request): array
    {
        return [
            'speedfactor' => Functions::fleetSpeedFactor(),
            'galaxy' => $this->planetInt('planet_galaxy'),
            'system' => $this->planetInt('planet_system'),
            'planet' => $this->planetInt('planet_planet'),
            'planet_type' => $this->planetInt('planet_type'),
            'galaxy_end' => $this->targetInput($request, 'galaxy', 1, MAX_GALAXY_IN_WORLD, $this->planetInt('planet_galaxy')),
            'system_end' => $this->targetInput($request, 'system', 1, MAX_SYSTEM_IN_GALAXY, $this->planetInt('planet_system')),
            'planet_end' => $this->targetInput($request, 'planet', 1, MAX_PLANET_IN_SYSTEM + 1, $this->planetInt('planet_planet')),
            'target_mission' => $request->has('target_mission') ? $request->integer('target_mission') : 0,
        ];
    }

    private function targetInput(Request $request, string $key, int $min, int $max, int $default): int
    {
        if (!$request->has($key)) {
            return $default;
        }

        $value = $request->integer($key);

        return $value >= $min && $value <= $max ? $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function coord(array $row, string $key): int
    {
        return $this->asInt($row[$key] ?? 0);
    }

    private function planetTypeShort(int $type): string
    {
        $labels = trans('game/global.planet_type_short');

        if (is_array($labels) && isset($labels[$type]) && is_scalar($labels[$type])) {
            return (string) $labels[$type];
        }

        return '';
    }

    private function driveLevel(string $drive): int
    {
        return $this->userInt('research_' . $drive . '_drive');
    }

    private function planetInt(string $key): int
    {
        $value = $this->planet[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function userInt(string $key): int
    {
        $value = $this->user[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function userStr(string $key): string
    {
        $value = $this->user[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
