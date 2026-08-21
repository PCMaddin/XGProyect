<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use App\Services\Game\Formulas\FormulasService;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator;
use App\Libraries\FleetsLib;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\Users;

/**
 * Sensor phalanx: scan the fleet movements around a target planet.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PhalanxController extends BaseController
{
    use PreparesLegacySql;

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    public function __construct(private FormulasService $formulasService)
    {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Galaxy));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();

        return $this->buildPage($request);
    }

    private function buildPage(Request $request): View | RedirectResponse
    {
        $range = $this->formulasService->phalanxRange($this->planetInt('building_phalanx'));
        $lowerSystem = max($this->planetInt('planet_system') - $range, 1);
        $upperSystem = min($this->planetInt('planet_system') + $range, MAX_SYSTEM_IN_GALAXY);

        $galaxy = $request->integer('galaxy');
        $system = $request->integer('system');
        $planet = $request->integer('planet');
        $planetType = $request->integer('planettype');

        if (
            $system < $lowerSystem
            || $system > $upperSystem
            || $galaxy !== $this->planetInt('planet_galaxy')
            || $planetType !== PlanetTypesEnumerator::PLANET
            || $this->planetInt('planet_type') !== PlanetTypesEnumerator::MOON
        ) {
            return redirect('game.php?page=galaxy');
        }

        $targetName = '';
        $fleetsTable = '';
        $deuteriumError = (string) __('game/phalanx.px_no_deuterium');

        if ($this->planetInt('planet_deuterium') >= 10000) {
            [$targetName, $fleetsTable] = $this->scan($galaxy, $system, $planet);
            $deuteriumError = '';
        }

        return view('galaxy.phalanx_body', [
            'phl_fleets_table' => $fleetsTable,
            'phl_er_deuter' => $deuteriumError,
            'phl_pl_galaxy' => $galaxy,
            'phl_pl_system' => $system,
            'phl_pl_place' => $planet,
            'phl_pl_name' => $targetName,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function scan(int $galaxy, int $system, int $planet): array
    {
        DB::update(
            $this->prepareSql('UPDATE `' . PLANETS . '` SET `planet_deuterium` = `planet_deuterium` - ? WHERE `planet_id` = ?;'),
            [PHALANX_COST, $this->userInt('current_planet')]
        );

        $targetRow = DB::selectOne(
            $this->prepareSql(
                'SELECT `planet_name`, `planet_user_id` FROM `' . PLANETS . '`
                WHERE `planet_galaxy` = ? AND `planet_system` = ? AND `planet_planet` = ? AND `planet_type` = 1;'
            ),
            [$galaxy, $system, $planet]
        );
        $target = is_object($targetRow) ? get_object_vars($targetRow) : [];
        $targetId = $this->asInt($target['planet_user_id'] ?? 0);
        $targetName = $this->asString($target['planet_name'] ?? '');

        $moonRow = DB::selectOne(
            $this->prepareSql(
                'SELECT `planet_destroyed` FROM `' . PLANETS . '`
                WHERE `planet_galaxy` = ? AND `planet_system` = ? AND `planet_planet` = ? AND `planet_type` = 3;'
            ),
            [$galaxy, $system, $planet]
        );
        $moonDestroyed = $this->moonDestroyed(is_object($moonRow) ? get_object_vars($moonRow) : null);

        $fleets = $this->rows(
            'SELECT f.*,
                po.`planet_name` AS `start_planet_name`,
                pt.`planet_name` AS `target_planet_name`,
                uo.`name` AS `start_planet_user`,
                ut.`name` AS `target_planet_user`
            FROM `' . FLEETS . '` f
                INNER JOIN `' . USERS . '` uo ON uo.`id` = f.`fleet_owner`
                LEFT JOIN `' . USERS . '` ut ON ut.`id` = f.`fleet_target_owner`
                INNER JOIN `' . PLANETS . '` po ON (
                    po.`planet_galaxy` = f.`fleet_start_galaxy` AND po.`planet_system` = f.`fleet_start_system`
                    AND po.`planet_planet` = f.`fleet_start_planet` AND po.`planet_type` = f.`fleet_start_type`)
                LEFT JOIN `' . PLANETS . '` pt ON (
                    pt.`planet_galaxy` = f.`fleet_end_galaxy` AND pt.`planet_system` = f.`fleet_end_system`
                    AND pt.`planet_planet` = f.`fleet_end_planet` AND pt.`planet_type` = f.`fleet_end_type`)
            WHERE (f.`fleet_start_galaxy` = ? AND f.`fleet_start_system` = ? AND f.`fleet_start_planet` = ?)
                OR (f.`fleet_end_galaxy` = ? AND f.`fleet_end_system` = ? AND f.`fleet_end_planet` = ?);',
            [$galaxy, $system, $planet, $galaxy, $system, $planet]
        );

        return [$targetName, $this->buildFleetsTable($fleets, $galaxy, $system, $planet, $targetId, $moonDestroyed)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fleets
     */
    private function buildFleetsTable(array $fleets, int $galaxy, int $system, int $planet, int $targetId, bool $moonDestroyed): string
    {
        $events = [];
        $record = 0;

        foreach ($fleets as $fleet) {
            $record++;

            // Legacy semantics: each fleet resets and rewrites its own event
            // timestamps, so the last fleet at a given time wins.
            foreach ($this->fleetEvents($fleet, $galaxy, $system, $planet, $targetId, $moonDestroyed, $record) as $time => $content) {
                $events[$time] = $content;
            }
        }

        ksort($events);

        return implode("\n", $events) . ($events === [] ? '' : "\n");
    }

    /**
     * Builds the visible sensor events for a single fleet, keyed by their
     * timestamp.
     *
     * @param  array<string, mixed>  $fleet
     *
     * @return array<int, string>
     *
     * @SuppressWarnings("PHPMD.NPathComplexity")
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    private function fleetEvents(array $fleet, int $galaxy, int $system, int $planet, int $targetId, bool $moonDestroyed, int $record): array
    {
        $fleet['fleet_resource_metal'] = 0;
        $fleet['fleet_resource_crystal'] = 0;
        $fleet['fleet_resource_deuterium'] = 0;

        $myFleet = $this->asInt($fleet['fleet_owner'] ?? 0) === $targetId;
        $arrive = $this->asInt($fleet['fleet_start_time'] ?? 0);
        $stay = $this->asInt($fleet['fleet_end_stay'] ?? 0);
        $return = $this->asInt($fleet['fleet_end_time'] ?? 0);
        $mission = $this->asInt($fleet['fleet_mission'] ?? 0);
        $startType = $this->asInt($fleet['fleet_start_type'] ?? 0);
        $endType = $this->asInt($fleet['fleet_end_type'] ?? 0);
        $startedHere = $this->atCoordinates($fleet, 'start', $galaxy, $system, $planet);
        $isTarget = $this->atCoordinates($fleet, 'end', $galaxy, $system, $planet);

        $events = [$arrive => '', $stay => '', $return => ''];

        // Inbound fleet: visible from the fleet's start planet (or a destroyed
        // moon), unless it is a hold mission started here.
        if ($arrive > time()) {
            if ($startedHere && $this->visibleType($startType, $moonDestroyed) && $mission !== 4) {
                $events[$arrive] .= "\n" . FleetsLib::flyingFleetsTable($fleet, 0, $myFleet, 'fs', $record, $this->user);
            } elseif (!$startedHere && $this->visibleType($endType, $moonDestroyed)) {
                $events[$arrive] .= "\n" . FleetsLib::flyingFleetsTable($fleet, 0, $myFleet, 'fs', $record, $this->user);
            }
        }

        // Fleet holding on the target planet.
        if ($stay > time() && $mission === 5 && $this->visibleType($endType, $moonDestroyed) && $isTarget) {
            $events[$stay] .= "\n" . FleetsLib::flyingFleetsTable($fleet, 1, $myFleet, 'ft', $record, $this->user);
        }

        // Returning fleet: visible from the planet it started at, except hold/mip.
        if ($return > time() && $mission !== 4 && $mission !== 10 && $startedHere && $this->visibleType($startType, $moonDestroyed)) {
            $events[$return] .= "\n" . FleetsLib::flyingFleetsTable($fleet, 2, $myFleet, 'fe', $record, $this->user);
        }

        return $events;
    }

    /**
     * A fleet endpoint is scannable when it sits on a planet, or on a moon that
     * has been destroyed (or is absent).
     */
    private function visibleType(int $planetType, bool $moonDestroyed): bool
    {
        return $planetType === PlanetTypesEnumerator::PLANET
            || ($planetType === PlanetTypesEnumerator::MOON && $moonDestroyed);
    }

    /**
     * The target moon counts as destroyed when it does not exist, or when its
     * destruction timestamp is set. The legacy code compared an array against
     * false, so the "no moon" case was never detected.
     *
     * @param  array<string, mixed>|null  $moon
     */
    private function moonDestroyed(?array $moon): bool
    {
        return $moon === null || $this->asInt($moon['planet_destroyed'] ?? 0) !== 0;
    }

    /**
     * @param  array<string, mixed>  $fleet
     */
    private function atCoordinates(array $fleet, string $prefix, int $galaxy, int $system, int $planet): bool
    {
        return $this->asInt($fleet['fleet_' . $prefix . '_galaxy'] ?? 0) === $galaxy
            && $this->asInt($fleet['fleet_' . $prefix . '_system'] ?? 0) === $system
            && $this->asInt($fleet['fleet_' . $prefix . '_planet'] ?? 0) === $planet;
    }

    /**
     * @param  array<int, mixed>  $bindings
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $sql, array $bindings = []): array
    {
        return array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select($this->prepareSql($sql), $bindings)
        );
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

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
