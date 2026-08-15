<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\Users;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class HighscoreController extends BaseController
{
    use PreparesLegacySql;

    private const MESSAGE_ICON = 'assets/upload/skins/xgproyect/img/m.gif';

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    public function __construct(private FormatService $formatService)
    {
    }

    public function __invoke(Request $request): View
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Statistics));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();

        return $this->buildPage($request);
    }

    private function buildPage(Request $request): View
    {
        $who = $request->integer('who', 1);
        $type = $request->integer('type', 1);
        $range = $request->integer('range', 1);

        if ($type === 5) {
            // Backward compatibility for the old defense filter.
            $type = 4;
        }

        $mapping = $this->rankingType($type);

        [$rangeOptions, $header, $values] = $who === 2
            ? $this->buildAllianceRanking($mapping, $range)
            : $this->buildPlayerRanking($mapping, $range);

        return view('highscore.body', [
            'gameTitle' => app(SettingsService::class)->getString('game_name'),
            'who' => $this->buildOptions([
                1 => __('game/highscore.st_player'),
                2 => __('game/highscore.st_alliance'),
            ], $who),
            'type' => $this->buildOptions([
                1 => __('game/highscore.st_total'),
                2 => __('game/highscore.st_economy'),
                3 => __('game/highscore.st_research'),
                4 => __('game/highscore.st_military'),
            ], $type),
            'range' => $rangeOptions,
            'stat_header' => $header,
            'stat_values' => $values,
        ]);
    }

    /**
     * @param  array<string, string>  $mapping
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function buildPlayerRanking(array $mapping, int $range): array
    {
        $rangeOptions = $this->buildRangeList($this->toInt($this->planet, 'stats_users'), $range);
        $header = view('highscore.player_header')->render();

        $start = $this->pageOffset($range);
        $adminLevel = app(SettingsService::class)->getInt('stat_admin_level');

        $rows = $this->fetchRows(
            'SELECT
                s.*,
                u.`id`,
                u.`name`,
                u.`ally_id`,
                a.`alliance_name`
            FROM `' . USERS_STATISTICS . '` AS s
            INNER JOIN `' . USERS . '` AS u ON u.`id` = s.`user_statistic_user_id`
            LEFT JOIN `' . ALLIANCE . '` AS a ON a.`alliance_id` = u.`ally_id`
            WHERE `authlevel` <= ' . $adminLevel . '
            ORDER BY `user_statistic_' . $mapping['order'] . '` DESC, `user_statistic_total_rank` ASC
            LIMIT ' . $start . ', 100;'
        );

        $position = $start + 1;
        $values = '';
        $currentUserId = $this->toInt($this->user, 'id');
        $currentAlliance = $this->toStr($this->user, 'alliance_name');

        foreach ($rows as $row) {
            $rowId = $this->toInt($row, 'id');
            $name = $this->toStr($row, 'name');
            $ranking = $this->toInt($row, 'user_statistic_' . $mapping['oldrank'])
                - $this->toInt($row, 'user_statistic_' . $mapping['rank']);

            $values .= view('highscore.player_table', [
                'player_rank' => $position,
                'player_rankplus' => $this->rankDifference($ranking),
                'player_name' => $rowId === $currentUserId
                    ? '<font color="lime">' . $name . '</font>'
                    : $name,
                'player_mes' => $rowId === $currentUserId
                    ? ''
                    : '<a href="game.php?page=chat&playerId=' . $rowId . '"><img src="' . asset(self::MESSAGE_ICON) . '" border="0" title="' . __('game/global.write_message') . '" /></a>',
                'player_alliance' => $this->allianceLink(
                    $this->toStr($row, 'alliance_name'),
                    $this->toInt($row, 'ally_id'),
                    $currentAlliance
                ),
                'player_points' => $this->formatService->prettyNumber($this->toInt($row, 'user_statistic_' . $mapping['order'])),
            ])->render();

            $position++;
        }

        return [$rangeOptions, $header, $values];
    }

    /**
     * @param  array<string, string>  $mapping
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function buildAllianceRanking(array $mapping, int $range): array
    {
        $countRow = DB::selectOne($this->prepareSql('SELECT COUNT(`alliance_id`) AS `count` FROM `' . ALLIANCE . '`;'));
        $maxAllys = is_object($countRow) ? $this->toInt(get_object_vars($countRow), 'count') : 0;

        $rangeOptions = $this->buildRangeList($maxAllys, $range);
        $header = view('highscore.alliance_header')->render();

        $start = $this->pageOffset($range);

        $rows = $this->fetchRows(
            'SELECT
                s.*,
                a.`alliance_id`,
                a.`alliance_tag`,
                a.`alliance_name`,
                a.`alliance_request_notallow`,
                (
                    SELECT COUNT(id)
                    FROM `' . USERS . '`
                    WHERE `ally_id` = a.`alliance_id`
                ) AS `ally_members`
            FROM `' . ALLIANCE_STATISTICS . '` AS s
            INNER JOIN `' . ALLIANCE . '` AS a ON a.`alliance_id` = s.`alliance_statistic_alliance_id`
            ORDER BY `alliance_statistic_' . $mapping['order'] . '` DESC, `alliance_statistic_total_rank` ASC
            LIMIT ' . $start . ', 100;'
        );

        $position = $start + 1;
        $values = '';

        foreach ($rows as $row) {
            $allyId = $this->toInt($row, 'alliance_id');
            $members = $this->toInt($row, 'ally_members');
            $points = $this->toInt($row, 'alliance_statistic_' . $mapping['order']);
            $ranking = $this->toInt($row, 'alliance_statistic_' . $mapping['oldrank'])
                - $this->toInt($row, 'alliance_statistic_' . $mapping['rank']);

            $values .= view('highscore.alliance_table', [
                'ally_rank' => $position,
                'ally_rankplus' => $this->rankDifference($ranking),
                'ally_id' => $allyId,
                'alliance_name' => $this->toStr($row, 'alliance_name'),
                'ally_members' => $members,
                'ally_action' => $this->toInt($row, 'alliance_request_notallow') === 1
                    ? '<a href="game.php?page=alliance&mode=apply&allyid=' . $allyId . '"><img src="' . asset(self::MESSAGE_ICON) . '" border="0" title="' . __('game/statistics.st_ally_request') . '" /></a>'
                    : '',
                'ally_points' => $this->formatService->prettyNumber($points),
                'ally_members_points' => $this->formatService->prettyNumber($members > 0 ? (int) floor($points / $members) : 0),
            ])->render();

            $position++;
        }

        return [$rangeOptions, $header, $values];
    }

    private function allianceLink(string $allianceName, int $allyId, string $currentAlliance): string
    {
        if ($allianceName === '') {
            return '';
        }

        $label = $allianceName === $currentAlliance
            ? '<font color="#33CCFF">[' . $allianceName . ']</font>'
            : '[' . $allianceName . ']';

        return '<a href="game.php?page=alliance&mode=ainfo&allyid=' . $allyId . '">' . $label . '</a>';
    }

    private function rankDifference(int $ranking): string
    {
        if ($ranking === 0) {
            return '<font color="#87CEEB">*</font>';
        }

        if ($ranking < 0) {
            return '<font color="red">' . $ranking . '</font>';
        }

        return '<font color="green">+' . $ranking . '</font>';
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function buildOptions(array $labels, int $selected): string
    {
        $html = '';

        foreach ($labels as $value => $label) {
            $html .= '<option value="' . $value . '"' . ($value === $selected ? ' SELECTED' : '') . '>' . $label . '</option>';
        }

        return $html;
    }

    private function buildRangeList(int $count, int $range): string
    {
        $list = '';
        $lastPage = $count > 100 ? (int) floor($count / 100) : 0;

        for ($page = 0; $page <= $lastPage; $page++) {
            $pageValue = $page * 100 + 1;
            $pageRange = $pageValue + 99;
            $list .= '<option value="' . $pageValue . '"'
                . (($range >= $pageValue && $range <= $pageRange) ? ' SELECTED' : '')
                . '>' . $pageValue . '-' . $pageRange . '</option>';
        }

        return $list;
    }

    /**
     * Returns the ordering/ranking column mapping for the OGame highscore categories.
     *
     * @return array<string, string>
     */
    private function rankingType(int $type): array
    {
        return match ($type) {
            2 => [
                'order' => 'buildings_points',
                'points' => 'buildings_points',
                'rank' => 'buildings_rank',
                'oldrank' => 'buildings_old_rank',
            ],
            3 => [
                'order' => 'technology_points',
                'points' => 'technology_points',
                'rank' => 'technology_rank',
                'oldrank' => 'technology_old_rank',
            ],
            4 => [
                'order' => 'military_points',
                'points' => 'military_points',
                'rank' => 'military_rank',
                'oldrank' => 'military_old_rank',
            ],
            default => [
                'order' => 'total_points',
                'points' => 'total_points',
                'rank' => 'total_rank',
                'oldrank' => 'total_old_rank',
            ],
        };
    }

    private function pageOffset(int $range): int
    {
        return (intdiv($range, 100) % 100) * 100;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(string $sql): array
    {
        return array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select($this->prepareSql($sql))
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toInt(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toStr(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
