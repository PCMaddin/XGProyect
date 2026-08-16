<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\Formulas\OfficerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\GalaxyLib;
use Xgp\App\Libraries\Users;

/**
 * Galaxy view. This is the display half of the migration; the fleet and
 * missile dispatch actions are added in the following stage, so the legacy
 * controller still serves game.php?page=galaxy (this one is not promoted yet).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class GalaxyController extends BaseController
{
    use PreparesLegacySql;

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    /** @var array<int, array<string, mixed>> */
    private array $galaxy = [];

    private int $planetCount = 0;

    private int $currentGalaxy = 0;

    private int $currentSystem = 0;

    public function __construct(
        private FormatService $formatService,
        private FleetsService $fleetsService,
        private OfficerService $officerService,
    ) {
    }

    public function __invoke(Request $request): View
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Galaxy));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();

        if ($this->userInt('preference_vacation_mode') > 0) {
            Functions::message((string) __('game/galaxy.gl_no_access_vm_on'), '', 0);
        }

        return $this->buildPage($request);
    }

    private function buildPage(Request $request): View
    {
        $mode = $request->integer('mode');
        $position = $this->validatePosition($request, $mode);
        $this->currentGalaxy = $position['galaxy'];
        $this->currentSystem = $position['system'];

        if ($mode === 2 && $this->planetInt('defense_interplanetary_missile') < 1) {
            Functions::message((string) __('game/galaxy.gl_no_missiles'), 'game.php?page=galaxy&mode=0', 2);
        }

        $this->galaxy = $this->loadSystem();

        $parse = [
            'selected_galaxy' => $this->currentGalaxy,
            'selected_system' => $this->currentSystem,
            'selected_planet' => $position['planet'],
            'currentmip' => $this->planetInt('defense_interplanetary_missile'),
            'maxfleetcount' => $this->currentFleets(),
            'fleetmax' => $this->fleetsService->getMaxFleets(
                $this->userInt('research_computer_technology'),
                $this->officerService->isOfficerActive($this->userInt('premium_officier_admiral'), time())
            ),
            'recyclers' => $this->formatService->prettyNumber($this->planetInt('ship_recycler')),
            'spyprobes' => $this->formatService->prettyNumber($this->planetInt('ship_espionage_probe')),
            'missile_count' => sprintf((string) __('game/galaxy.gl_missil_to_launch'), $this->planetInt('defense_interplanetary_missile')),
            'current' => $request->has('current') ? $request->integer('current') : null,
            'current_galaxy' => $this->planetInt('planet_galaxy'),
            'current_system' => $this->planetInt('planet_system'),
            'current_planet' => $this->planetInt('planet_planet'),
            'coords' => $this->formatService->prettyCoords($this->currentGalaxy, $this->currentSystem, $position['planet']),
            'planet_type' => $this->planetInt('planet_type'),
        ];

        $parse['mip'] = $mode === 2 ? view('galaxy.galaxy_missile_selector', $parse)->render() : ' ';

        return view('galaxy.galaxy_view', array_merge(
            [
                'list_of_positions' => $this->buildPositionsList(),
                'planet_count' => $this->planetCount,
                'max_galaxy' => MAX_GALAXY_IN_WORLD,
                'max_system' => MAX_SYSTEM_IN_GALAXY,
            ],
            $parse
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSystem(): array
    {
        return array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql(
                    'SELECT
                        (SELECT CONCAT(GROUP_CONCAT(`buddy_receiver`), \',\', GROUP_CONCAT(`buddy_sender`))
                            FROM `' . BUDDY . '` AS b
                            WHERE b.`buddy_receiver` = ? OR b.`buddy_sender` = ?) AS `buddys`,
                        p.`planet_debris_metal` AS `metal`, p.`planet_debris_crystal` AS `crystal`,
                        p.`planet_id` AS `id_planet`, p.`planet_galaxy`, p.`planet_system`, p.`planet_planet`,
                        p.`planet_type`, p.`planet_destroyed`, p.`planet_name`, p.`planet_image`,
                        p.`planet_last_update`, p.`planet_user_id`,
                        u.`id`, u.`ally_id`, b.`user_id` AS `banned`, pr.`preference_vacation_mode`,
                        u.`onlinetime`, u.`name`, u.`authlevel`,
                        s.`user_statistic_total_rank`, s.`user_statistic_total_points`,
                        m.`planet_id` AS `id_luna`, m.`planet_diameter`, m.`planet_temp_min`,
                        m.`planet_destroyed` AS `destroyed_moon`, m.`planet_name` AS `name_moon`,
                        a.`alliance_name`, a.`alliance_tag`, a.`alliance_web`,
                        (SELECT COUNT(`id`) FROM `' . USERS . '` WHERE `ally_id` = a.`alliance_id`) AS `ally_members`
                    FROM `' . PLANETS . '` AS p
                        INNER JOIN `' . USERS . '` AS u ON p.`planet_user_id` = u.`id`
                        INNER JOIN `' . PREFERENCES . '` AS pr ON pr.`preference_user_id` = u.`id`
                        INNER JOIN `' . USERS_STATISTICS . '` AS s ON s.`user_statistic_user_id` = u.`id`
                        LEFT JOIN `' . ALLIANCE . '` AS a ON a.`alliance_id` = u.`ally_id`
                        LEFT JOIN `' . PLANETS . '` AS m ON m.`planet_id` = (
                            SELECT mp.`planet_id` FROM `' . PLANETS . '` AS mp
                            WHERE mp.`planet_galaxy` = p.`planet_galaxy` AND mp.`planet_system` = p.`planet_system`
                                AND mp.`planet_planet` = p.`planet_planet` AND mp.`planet_type` = 3)
                        LEFT JOIN `' . BANNED . '` AS b ON b.`user_id` = u.`id`
                    WHERE p.`planet_galaxy` = ? AND p.`planet_system` = ? AND p.`planet_type` = 1
                        AND p.`planet_planet` > 0 AND p.`planet_planet` <= ?
                    ORDER BY p.`planet_planet`;'
                ),
                [$this->userInt('id'), $this->userInt('id'), $this->currentGalaxy, $this->currentSystem, MAX_PLANET_IN_SYSTEM]
            )
        );
    }

    private function currentFleets(): int
    {
        $row = DB::selectOne(
            $this->prepareSql('SELECT COUNT(`fleet_id`) AS total_fleets FROM `' . FLEETS . '` WHERE `fleet_owner` = ?;'),
            [$this->userInt('id')]
        );

        return is_object($row) ? $this->asInt(get_object_vars($row)['total_fleets'] ?? 0) : 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPositionsList(): array
    {
        $positions = [];
        $galaxyRow = new GalaxyLib($this->user, $this->planet, $this->currentGalaxy, $this->currentSystem);

        foreach ($this->galaxy as $planet) {
            $this->planetCount++;
            $slot = $this->asInt($planet['planet_planet'] ?? 0);
            $positions[$slot] = $galaxyRow->buildRow($planet, $slot);
        }

        for ($slot = 1; $slot <= MAX_PLANET_IN_SYSTEM; $slot++) {
            $positions[$slot] ??= [
                'pos' => $slot,
                'planet' => '',
                'planetname' => '',
                'moon' => '',
                'debris' => '',
                'username' => '',
                'alliance' => '',
                'actions' => '',
            ];
        }

        ksort($positions);

        return $positions;
    }

    /**
     * @return array{galaxy: int, system: int, planet: int}
     */
    private function validatePosition(Request $request, int $mode): array
    {
        return match ($mode) {
            1 => $this->navigatePosition($request),
            2 => [
                'galaxy' => $request->integer('galaxy'),
                'system' => $request->integer('system'),
                'planet' => $request->integer('planet'),
            ],
            3 => [
                'galaxy' => $request->integer('galaxy'),
                'system' => $request->integer('system'),
                'planet' => 0,
            ],
            0 => [
                'galaxy' => $this->planetInt('planet_galaxy'),
                'system' => $this->planetInt('planet_system'),
                'planet' => $this->planetInt('planet_planet'),
            ],
            default => ['galaxy' => 1, 'system' => 1, 'planet' => 0],
        };
    }

    /**
     * @return array{galaxy: int, system: int, planet: int}
     */
    private function navigatePosition(Request $request): array
    {
        $galaxy = max($request->integer('galaxy'), 1);
        $system = max($request->integer('system'), 1);

        if ($request->has('galaxyRight')) {
            $galaxy = $galaxy >= MAX_GALAXY_IN_WORLD ? 1 : $galaxy + 1;
        }

        if ($request->has('galaxyLeft')) {
            $galaxy = $galaxy <= 1 ? MAX_GALAXY_IN_WORLD : $galaxy - 1;
        }

        if ($request->has('systemRight')) {
            $system = $system >= MAX_SYSTEM_IN_GALAXY ? 1 : $system + 1;
        }

        if ($request->has('systemLeft')) {
            $system = $system <= 1 ? MAX_SYSTEM_IN_GALAXY : $system - 1;
        }

        return ['galaxy' => $galaxy, 'system' => $system, 'planet' => 0];
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

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
