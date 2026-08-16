<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\TimingService;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator;
use Xgp\App\Core\Objects;
use Xgp\App\Libraries\DevelopmentsLib;
use Xgp\App\Libraries\FleetsLib;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\NoobsProtectionLib;
use Xgp\App\Libraries\UpdatesLibrary;
use Xgp\App\Libraries\Users;

/**
 * Overview: the game's landing page (planet summary, fleet movements,
 * colonies and rank).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class OverviewController extends BaseController
{
    use PreparesLegacySql;

    private const PLANET_ASSET = 'assets/upload/skins/xgproyect/planets/';

    /** Object id of the terraformer, used for the maximum-fields bonus. */
    private const TERRAFORMER = 33;

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    private NoobsProtectionLib $noob;

    private Objects $objects;

    public function __construct(
        private FormatService $formatService,
        private DevelopmentsService $developmentsService,
        private TimingService $timingService,
    ) {
    }

    public function __invoke(): View
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Overview));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();
        $this->noob = new NoobsProtectionLib();
        $this->objects = new Objects();

        return view('overview.view', array_merge(
            [
                'planetName' => $this->planetStr('planet_name'),
                'username' => $this->userStr('name'),
                'dateTime' => $this->timingService->formatExtendedDate(time()),
                'newMessage' => $this->getMessages(),
                'fleetList' => $this->getFleetMovements(),
                'planetImage' => $this->planetStr('planet_image'),
                'building' => $this->getCurrentWork($this->planet),
                'otherPlanets' => $this->getPlanets(),
                'planetDiameter' => $this->formatService->prettyNumber($this->planetInt('planet_diameter')),
                'planetCurrentFields' => $this->planetInt('planet_field_current'),
                'planetMaxFields' => $this->developmentsService->maxFields(
                    $this->planetInt('planet_field_max'),
                    $this->planetInt($this->objectName(self::TERRAFORMER))
                ),
                'planetMinTemp' => $this->planetInt('planet_temp_min'),
                'planetMaxTemp' => $this->planetInt('planet_temp_max'),
                'galaxyGalaxy' => $this->planetInt('planet_galaxy'),
                'galaxySystem' => $this->planetInt('planet_system'),
                'galaxyPlanet' => $this->planetInt('planet_planet'),
                'userRank' => $this->getUserRank(),
            ],
            $this->getPlanetMoon()
        ));
    }

    /**
     * @param  array<string, mixed>  $planet
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function getCurrentWork(array $planet, bool $isCurrentPlanet = true): string
    {
        if (!$isCurrentPlanet) {
            UpdatesLibrary::updateBuildingsQueue($planet, $this->user);
        }

        if ($this->arrInt($planet, 'planet_b_building') === 0) {
            return (string) __('game/overview.ov_free');
        }

        $current = explode(',', explode(';', $this->arrStr($planet, 'planet_b_building_id'))[0]);
        $building = (int) $current[0];
        $level = (int) ($current[1] ?? 0);
        $timeToEnd = (int) ($current[3] ?? 0) - time();
        $name = (string) __('game/constructions.' . $this->objectName($building)) . ' (' . $level . ')';

        if (!$isCurrentPlanet) {
            return $name . '<br><font color="#7f7f7f">(' . $this->formatService->prettyTime((float) $timeToEnd) . ')</font>';
        }

        return DevelopmentsLib::currentBuilding('overview', $building)
            . $name
            . '<br><div id="blc" class="z">' . $this->formatService->prettyTime((float) $timeToEnd) . '</div>'
            . "\n<script language=\"JavaScript\">"
            . "\n	pp = \"" . $timeToEnd . "\";\n"
            . "\n	pk = \"1\";\n"
            . "\n	pm = \"cancel\";\n"
            . "\n	pl = \"" . $this->planetInt('planet_id') . "\";\n"
            . "\n	t();\n"
            . "\n</script>\n";
    }

    private function getMessages(): string
    {
        $count = $this->userInt('new_message');

        if ($count === 0) {
            return '';
        }

        $text = $count === 1
            ? (string) __('game/overview.ov_have_new_message')
            : str_replace('%m', $this->formatService->prettyNumber($count), (string) __('game/overview.ov_have_new_messages'));

        return '<tr><th role="cell" colspan="4">'
            . $this->formatService->link('game.php?page=messages', $text, $text)
            . '</th></tr>';
    }

    private function getFleetMovements(): string
    {
        $rows = [];
        $record = 0;

        foreach ($this->fleetRows() as $fleet) {
            $this->collectFleetEvents($fleet, $rows, $record);
        }

        if ($rows === []) {
            return '';
        }

        ksort($rows);

        return implode('', array_map(static fn (string $content): string => $content . "\n", $rows));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fleetRows(): array
    {
        $userId = $this->userInt('id');

        if ($userId <= 0) {
            return [];
        }

        return array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql(
                    'SELECT DISTINCT fleets.*,
                        po.`planet_name` AS `start_planet_name`,
                        pt.`planet_name` AS `target_planet_name`,
                        uo.`name` AS `start_planet_user`,
                        ut.`name` AS `target_planet_user`,
                        (SELECT GROUP_CONCAT(am.`acs_user_id`) FROM `' . ACS_MEMBERS . '` am
                            WHERE am.`acs_group_id` = fleets.`fleet_group`) AS `acs_members`
                    FROM (
                        SELECT f.* FROM `' . FLEETS . '` f
                            WHERE f.`fleet_owner` = ? OR f.`fleet_target_owner` = ?
                        UNION ALL
                        SELECT f.* FROM `' . ACS_MEMBERS . '` am
                            LEFT JOIN `' . FLEETS . '` f ON f.`fleet_group` = am.`acs_group_id`
                            WHERE f.`fleet_id` IS NOT NULL AND am.`acs_user_id` = ?
                    ) fleets
                    INNER JOIN `' . USERS . '` uo ON uo.`id` = fleets.`fleet_owner`
                    LEFT JOIN `' . USERS . '` ut ON ut.`id` = fleets.`fleet_target_owner`
                    INNER JOIN `' . PLANETS . '` po ON (
                        po.`planet_galaxy` = fleets.`fleet_start_galaxy` AND po.`planet_system` = fleets.`fleet_start_system`
                        AND po.`planet_planet` = fleets.`fleet_start_planet` AND po.`planet_type` = fleets.`fleet_start_type`)
                    LEFT JOIN `' . PLANETS . '` pt ON (
                        pt.`planet_galaxy` = fleets.`fleet_end_galaxy` AND pt.`planet_system` = fleets.`fleet_end_system`
                        AND pt.`planet_planet` = fleets.`fleet_end_planet` AND pt.`planet_type` = fleets.`fleet_end_type`);'
                ),
                [$userId, $userId, $userId]
            )
        );
    }

    /**
     * @param  array<string, mixed>  $fleet
     * @param  array<string, string>  $rows
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    private function collectFleetEvents(array $fleet, array &$rows, int &$record): void
    {
        $id = $this->asInt($fleet['fleet_id'] ?? 0);
        $mission = $this->asInt($fleet['fleet_mission'] ?? 0);
        $isOwn = $this->asInt($fleet['fleet_owner'] ?? 0) === $this->userInt('id');
        $start = $this->asInt($fleet['fleet_start_time'] ?? 0);
        $stay = $this->asInt($fleet['fleet_end_stay'] ?? 0);
        $end = $this->asInt($fleet['fleet_end_time'] ?? 0);

        if ($isOwn) {
            $record++;
            $this->put($rows, $start, $id, $start > time() ? FleetsLib::flyingFleetsTable($fleet, 0, true, 'fs', $record, $this->user) : '');

            if ($mission !== 4 && $mission !== 10) {
                $this->put($rows, $stay, $id, $stay > time() ? FleetsLib::flyingFleetsTable($fleet, 1, true, 'ft', $record, $this->user) : '');
                $this->put($rows, $end, $id, $end > time() ? FleetsLib::flyingFleetsTable($fleet, 2, true, 'fe', $record, $this->user) : '');
            }

            if ($mission === 4 && $start < time() && $end > time()) {
                $this->put($rows, $end, $id, FleetsLib::flyingFleetsTable($fleet, 2, true, 'none', $record, $this->user));
            }

            return;
        }

        $hidden = $this->asInt($fleet['fleet_mess'] ?? 0) > 0;

        if ($mission === 2 || ($mission === 1 && $this->asInt($fleet['fleet_group'] ?? 0) > 0)) {
            $record++;
            $this->put($rows, $start, $id, !$hidden && $start > time() ? FleetsLib::flyingFleetsTable($fleet, 0, false, 'ofs', $record, $this->user) : '');
        }

        if ($mission !== 8) {
            $record++;
            $acsMember = in_array($this->userInt('id'), array_map(intval(...), explode(',', $this->asString($fleet['acs_members'] ?? ''))), true);
            $this->put($rows, $start, $id, $start > time() ? FleetsLib::flyingFleetsTable($fleet, 0, false, 'ofs', $record, $this->user, $acsMember) : '');

            if ($mission === 5) {
                $this->put($rows, $stay, $id, $stay > time() ? FleetsLib::flyingFleetsTable($fleet, 1, false, 'oft', $record, $this->user, $acsMember) : '');
            }
        }
    }

    /**
     * @param  array<string, string>  $rows
     */
    private function put(array &$rows, int $time, int $id, string $content): void
    {
        $key = $time . '_' . $id;

        // Legacy semantics: a rendered table overwrites the slot, an empty
        // result only initialises a new slot.
        if ($content !== '') {
            $rows[$key] = $content;
        } elseif (!isset($rows[$key])) {
            $rows[$key] = '';
        }
    }

    /**
     * @return array<string, string>
     */
    private function getPlanetMoon(): array
    {
        if (
            $this->planetInt('moon_id') === 0
            || $this->planetInt('moon_destroyed') !== 0
            || $this->planetInt('planet_type') !== PlanetTypesEnumerator::PLANET
        ) {
            return ['moonImg' => '', 'moon' => ''];
        }

        $moonName = $this->planetStr('moon_name') . ' (' . __('game/global.moon') . ')';
        $image = asset(self::PLANET_ASSET . $this->planetStr('moon_image') . '.jpg');

        return [
            'moonImg' => $this->formatService->link(
                'game.php?page=overview&cp=' . $this->planetInt('moon_id') . '&re=0',
                Functions::setImage($image, $moonName, 'height="50" width="50"'),
                $moonName
            ),
            'moon' => $moonName,
        ];
    }

    private function getPlanets(): string
    {
        $block = '<tr>';
        $colony = 1;

        foreach ($this->colonyRows() as $planet) {
            if (
                $this->arrInt($planet, 'planet_id') === $this->userInt('current_planet')
                || $this->arrInt($planet, 'planet_type') === PlanetTypesEnumerator::MOON
            ) {
                continue;
            }

            $image = asset(self::PLANET_ASSET . 'small/s_' . $this->arrStr($planet, 'planet_image') . '.jpg');

            $block .= '<th>' . $this->arrStr($planet, 'planet_name') . '<br>'
                . $this->formatService->link(
                    'game.php?page=overview&cp=' . $this->arrInt($planet, 'planet_id') . '&re=0',
                    Functions::setImage($image, $this->arrStr($planet, 'planet_name'), 'height="50" width="50"')
                )
                . '<center>' . $this->getCurrentWork($planet, false) . '</center></th>';

            $colony++;

            if ($colony > 2) {
                $block .= '</tr><tr>';
                $colony = 1;
            }
        }

        return $block . '</tr>';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function colonyRows(): array
    {
        $userId = $this->userInt('id');

        if ($userId <= 0) {
            return [];
        }

        return array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql(
                    'SELECT * FROM `' . PLANETS . '` AS p
                        INNER JOIN `' . BUILDINGS . '` AS b ON b.`building_planet_id` = p.`planet_id`
                        INNER JOIN `' . DEFENSES . '` AS d ON d.`defense_planet_id` = p.`planet_id`
                        INNER JOIN `' . SHIPS . '` AS s ON s.`ship_planet_id` = p.`planet_id`
                    WHERE p.`planet_user_id` = ? AND p.`planet_destroyed` = 0;'
                ),
                [$userId]
            )
        );
    }

    private function getUserRank(): string
    {
        if (!$this->noob->isRankVisible($this->userInt('authlevel'))) {
            return '-';
        }

        $totalRank = $this->userStr('user_statistic_total_rank') === ''
            ? $this->planetInt('stats_users')
            : $this->userInt('user_statistic_total_rank');

        return (string) __('game/overview.ov_place', [
            'points' => $this->formatService->prettyNumber($this->userInt('user_statistic_total_points')),
            'url' => $this->formatService->link(
                'game.php?page=statistics&range=' . $totalRank,
                (string) $totalRank,
                (string) $totalRank
            ),
            'total' => $this->planetInt('stats_users'),
        ]);
    }

    private function objectName(int $objectId): string
    {
        $name = $this->objects->getObjects($objectId);

        return is_string($name) ? $name : '';
    }

    private function planetInt(string $key): int
    {
        return $this->arrInt($this->planet, $key);
    }

    private function planetStr(string $key): string
    {
        return $this->arrStr($this->planet, $key);
    }

    private function userInt(string $key): int
    {
        return $this->arrInt($this->user, $key);
    }

    private function userStr(string $key): string
    {
        return $this->arrStr($this->user, $key);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function arrInt(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function arrStr(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

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
