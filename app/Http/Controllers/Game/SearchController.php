<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\SwitchIntEnumerator as SwitchInt;
use App\Libraries\Functions;
use App\Libraries\NoobsProtectionLib;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class SearchController extends BaseController
{
    use PreparesLegacySql;

    private const MESSAGE_ICON = 'assets/upload/skins/xgproyect/img/m.gif';
    private const BUDDY_ICON = 'assets/upload/skins/xgproyect/img/b.gif';

    /** @var array<string, string> */
    private const RESULT_TEMPLATES = [
        'playerName' => 'player_name',
        'allianceTag' => 'alliance_tag',
        'planetNames' => 'planet_names',
    ];

    private NoobsProtectionLib $noob;

    public function __construct(private FormatService $formatService)
    {
    }

    public function __invoke(Request $request): View
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Search));

        $this->noob = new NoobsProtectionLib();

        $rawType = $request->input('searchType');
        $rawText = $request->input('searchText');

        $searchType = $this->normalizeSearchType(is_string($rawType) ? $rawType : 'playerName');
        $searchText = is_string($rawText) ? trim($rawText) : '';

        $results = $searchText !== '' ? $this->runSearch($searchType, $searchText) : [];

        return view('search.view', $this->buildViewData($searchType, $searchText, $results));
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     *
     * @return array<string, mixed>
     */
    private function buildViewData(string $searchType, string $searchText, array $results): array
    {
        $data = [
            'playerName' => '',
            'allianceTag' => '',
            'planetNames' => '',
            'searchText' => $searchText,
            'errorBlock' => __('game/search.sh_error_empty'),
            'searchResults' => '',
        ];

        if ($searchText === '') {
            return $data;
        }

        $data[$searchType] = 'selected = "selected"';

        if ($results === []) {
            $data['errorBlock'] = __('game/search.sh_error_no_results_' . self::RESULT_TEMPLATES[$searchType]);

            return $data;
        }

        $data['errorBlock'] = '';
        $data['searchResults'] = view(
            'search.results.' . self::RESULT_TEMPLATES[$searchType],
            ['results' => $this->parseResults($searchType, $results)]
        )->render();

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runSearch(string $searchType, string $searchText): array
    {
        $like = '%' . $searchText . '%';

        return match ($searchType) {
            'allianceTag' => $this->fetchRows(
                'SELECT
                    a.`alliance_id`,
                    a.`alliance_name`,
                    a.`alliance_tag`,
                    a.`alliance_request_notallow` AS `alliance_requests`,
                    s.`alliance_statistic_total_points` AS `alliance_points`,
                    (
                        SELECT COUNT(id)
                        FROM `' . USERS . '`
                        WHERE `ally_id` = a.`alliance_id`
                    ) AS `alliance_members`
                FROM `' . ALLIANCE . '` AS a
                    LEFT JOIN `' . ALLIANCE_STATISTICS . '` AS s ON a.`alliance_id` = s.`alliance_statistic_alliance_id`
                WHERE (a.`alliance_name` LIKE ?) OR (a.`alliance_tag` LIKE ?)
                LIMIT ' . MAX_SEARCH_RESULTS . ';',
                [$like, $like]
            ),
            'planetNames' => $this->fetchRows(
                $this->playerSelect('p.`planet_user_id` = u.`id`', 'p.`planet_name` LIKE ?'),
                [$like]
            ),
            default => $this->fetchRows(
                $this->playerSelect('p.`planet_id` = u.`home_planet_id`', 'u.`name` LIKE ?'),
                [$like]
            ),
        };
    }

    private function playerSelect(string $planetJoin, string $where): string
    {
        return 'SELECT
                u.`id`,
                u.`name`,
                u.`authlevel`,
                p.`planet_name`,
                p.`planet_galaxy`,
                p.`planet_system`,
                p.`planet_planet`,
                s.`user_statistic_total_rank` AS `user_rank`,
                a.`alliance_id`,
                a.`alliance_name`
            FROM `' . USERS . '` AS u
                INNER JOIN `' . USERS_STATISTICS . '` AS s ON s.`user_statistic_user_id` = u.`id`
                INNER JOIN `' . PLANETS . '` AS p ON ' . $planetJoin . '
                LEFT JOIN `' . ALLIANCE . '` AS a ON a.`alliance_id` = u.`ally_id`
            WHERE ' . $where . '
            LIMIT ' . MAX_SEARCH_RESULTS . ';';
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseResults(string $searchType, array $results): array
    {
        $list = [];

        foreach ($results as $row) {
            $list[] = $searchType === 'allianceTag'
                ? array_merge($row, [
                    'alliance_points' => $this->formatService->prettyNumber($this->int($row, 'alliance_points')),
                    'alliance_actions' => $this->allianceApplicationAction(
                        $this->int($row, 'alliance_id'),
                        $this->int($row, 'alliance_requests')
                    ),
                ])
                : array_merge($row, [
                    'planet_position' => $this->formatService->prettyCoords(
                        $this->int($row, 'planet_galaxy'),
                        $this->int($row, 'planet_system'),
                        $this->int($row, 'planet_planet')
                    ),
                    'user_rank' => $this->rankPosition($this->int($row, 'user_rank'), $this->int($row, 'authlevel')),
                    'user_actions' => $this->playerActions($this->int($row, 'id')),
                ]);
        }

        return $list;
    }

    private function rankPosition(int $userRank, int $userLevel): string
    {
        if (!$this->noob->isRankVisible($userLevel)) {
            return '-';
        }

        return $this->formatService->link(
            'game.php?page=statistics&start=' . $userRank,
            $this->formatService->prettyNumber($userRank)
        );
    }

    private function playerActions(int $userId): string
    {
        $chatLink = $this->formatService->link(
            'game.php?page=chat&playerId=' . $userId,
            Functions::setImage(asset(self::MESSAGE_ICON), __('game/search.sh_tip_write')),
            __('game/search.sh_tip_apply')
        );

        $buddyLink = $this->formatService->link(
            '#',
            Functions::setImage(asset(self::BUDDY_ICON), __('game/search.sh_tip_buddy_request')),
            __('game/search.sh_tip_apply'),
            'onClick="f(\'game.php?page=buddies&mode=2&u=' . $userId . '\', \'' . __('game/search.sh_tip_buddy_request') . '\')"'
        );

        return $chatLink . ' ' . $buddyLink;
    }

    private function allianceApplicationAction(int $allianceId, int $allianceRequests): string
    {
        if ($allianceRequests !== SwitchInt::on) {
            return '';
        }

        return $this->formatService->link(
            'game.php?page=alliance&mode=apply&allyid=' . $allianceId,
            Functions::setImage(asset(self::MESSAGE_ICON), __('game/search.sh_tip_apply')),
            __('game/search.sh_tip_apply')
        );
    }

    private function normalizeSearchType(string $searchType): string
    {
        return array_key_exists($searchType, self::RESULT_TEMPLATES) ? $searchType : 'playerName';
    }

    /**
     * @param  array<int, mixed>  $bindings
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(string $sql, array $bindings = []): array
    {
        return array_map(
            fn (object $row): array => (array) $row,
            DB::select($this->prepareSql($sql), $bindings)
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
