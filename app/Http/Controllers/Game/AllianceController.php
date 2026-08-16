<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\TimingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\AllianceRanksEnumerator as AllianceRanks;
use Xgp\App\Libraries\Alliance\Alliances;
use Xgp\App\Libraries\BBCodeLib;
use Xgp\App\Helpers\UrlHelper;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\Users;

/**
 * Alliance page (public browsing and member front). The write actions
 * (make/apply/exit/memberslist/circular) and the admin subsystem are being
 * migrated in following stages; until then the legacy controller still serves
 * game.php?page=alliance (this controller is not yet promoted).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 */
class AllianceController extends BaseController
{
    use PreparesLegacySql;

    /** @var array<string, mixed> */
    private array $user = [];

    private BBCodeLib $bbcode;

    private Alliances $alliance;

    public function __construct(
        private FormatService $formatService,
        private TimingService $timingService,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Alliance));

        $this->user = Users::getInstance()->getUserData();
        $this->bbcode = new BBCodeLib();
        $this->alliance = $this->setUpAlliances($request);

        $section = $this->currentSection($request);

        if (!$this->isPageAllowed($section)) {
            return redirect('game.php?page=alliance');
        }

        return match ($section) {
            'ainfo' => $this->ainfoSection(),
            'search' => $this->searchSection($request),
            'make' => $this->makeSection($request),
            'apply' => $this->applySection($request),
            'exit' => $this->exitSection($request),
            'memberslist' => $this->memberslistSection($request),
            'circular' => $this->circularSection($request),
            default => $this->defaultSection($request),
        };
    }

    private function setUpAlliances(Request $request): Alliances
    {
        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT a.*,
                    (SELECT COUNT(id) FROM `' . USERS . '` WHERE `ally_id` = a.`alliance_id`) AS `alliance_members`
                FROM `' . ALLIANCE . '` AS a
                WHERE a.`alliance_id` = ?
                LIMIT 1;'
            ),
            [$this->allianceId($request)]
        );

        return new Alliances(
            [is_object($row) ? get_object_vars($row) : []],
            $this->userInt('id'),
            $this->userInt('ally_rank_id')
        );
    }

    private function currentSection(Request $request): string
    {
        $mode = $request->query('mode');

        return is_string($mode) && $mode !== '' ? $mode : 'default';
    }

    private function userAccess(): string
    {
        if ($this->userInt('ally_id') === 0) {
            return $this->userInt('ally_request') === 0 ? 'public' : 'awaitingApproval';
        }

        return 'isMember';
    }

    private function allianceId(Request $request): int
    {
        $requested = $request->integer('allyid');

        if ($requested !== 0) {
            return $requested;
        }

        if ($this->userInt('ally_id') !== 0) {
            return $this->userInt('ally_id');
        }

        return $this->userInt('ally_request');
    }

    private function isPageAllowed(string $section): bool
    {
        $allowed = [
            'public' => ['default', 'ainfo', 'make', 'search', 'apply'],
            'awaitingApproval' => ['default', 'ainfo'],
            'isMember' => ['default', 'ainfo', 'exit', 'memberslist', 'circular', 'admin'],
        ];

        return in_array($section, $allowed[$this->userAccess()] ?? [], true);
    }

    private function defaultSection(Request $request): View
    {
        return match ($this->userAccess()) {
            'awaitingApproval' => $this->awaitingApprovalSection($request),
            'isMember' => $this->memberFrontSection(),
            default => view('alliance.start'),
        };
    }

    private function awaitingApprovalSection(Request $request): View
    {
        $requestText = (string) __('game/alliance.al_request_wait_message');
        $buttonText = (string) __('game/alliance.al_delete_request');

        if ($request->filled('bcancel')) {
            DB::update(
                $this->prepareSql('UPDATE `' . USERS . '` SET `ally_request` = 0 WHERE `id` = ?;'),
                [$this->userInt('id')]
            );

            $requestText = (string) __('game/alliance.al_request_deleted');
            $buttonText = (string) __('game/alliance.al_continue');
        }

        return view('alliance.awaiting', [
            'request_text' => str_replace('%s', $this->currentAllianceTag(), $requestText),
            'button_text' => $buttonText,
        ]);
    }

    private function memberFrontSection(): View
    {
        $blocks = [
            $this->tagBlock(),
            $this->nameBlock(),
            $this->membersBlock(),
            $this->rankBlock(),
            $this->requestsBlock(),
            $this->circularBlock(),
        ];

        $details = array_values(array_filter(
            $blocks,
            fn (array $block): bool => $block['detail_content'] !== ''
        ));

        return view('alliance.front', [
            'image' => $this->imageBlock(),
            'details' => $details,
            'description' => $this->descriptionBlock(),
            'text' => $this->textBlock(),
            'leave' => !$this->alliance->isOwner(),
        ]);
    }

    private function ainfoSection(): View
    {
        $current = $this->alliance->getCurrentAlliance();

        return view('alliance.ainfo', [
            'image' => $this->imageBlock(),
            'tag' => $current->getAllianceTag(),
            'name' => $current->getAllianceName(),
            'members' => $this->asString($current->getAllianceMembers()),
            'description' => $this->descriptionBlock(),
            'web' => $this->webBlock(),
            'requests' => $this->publicRequestsBlock(),
        ]);
    }

    private function searchSection(Request $request): View
    {
        $search = is_string($raw = $request->input('searchtext')) ? $raw : '';
        $results = [];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $alliances = new Alliances(
                array_map(
                    fn (object $row): array => get_object_vars($row),
                    DB::select(
                        $this->prepareSql(
                            'SELECT a.`alliance_id`, a.`alliance_tag`, a.`alliance_name`,
                                (SELECT COUNT(id) FROM `' . USERS . '` WHERE `ally_id` = a.`alliance_id`) AS `alliance_members`
                            FROM `' . ALLIANCE . '` AS a
                            WHERE a.`alliance_name` LIKE ? OR a.`alliance_tag` LIKE ?
                            LIMIT 30;'
                        ),
                        [$like, $like]
                    )
                ),
                $this->userInt('id')
            );

            foreach ($alliances->getAlliances() as $result) {
                $results[] = [
                    'ally_tag' => $this->formatService->link(
                        'game.php?page=alliance&mode=apply&allyid=' . $result->getAllianceId(),
                        $result->getAllianceTag()
                    ),
                    'alliance_name' => $result->getAllianceName(),
                    'ally_members' => $this->asString($result->getAllianceMembers()),
                ];
            }
        }

        return view('alliance.search', [
            'searchtext' => $search,
            'searchResults' => $results,
        ]);
    }

    private function makeSection(Request $request): View
    {
        if ($request->isMethod('post') && $request->filled('atag') && $request->filled('aname')) {
            $this->createAlliance($request);
        }

        return view('alliance.make');
    }

    private function createAlliance(Request $request): void
    {
        $tag = is_string($rawTag = $request->input('atag')) ? $rawTag : '';
        $name = is_string($rawName = $request->input('aname')) ? $rawName : '';
        $makeUrl = 'game.php?page=alliance&mode=make';

        if (strlen($tag) < 3 || strlen($tag) > 8) {
            Functions::message((string) __('game/alliance.al_tag_required'), $makeUrl, 3);
        }

        if ($this->allianceTagExists($tag) !== null) {
            Functions::message(strtr((string) __('game/alliance.al_tag_already_exists'), ['%s' => $tag]), $makeUrl, 3);
        }

        if (strlen($name) < 3 || strlen($name) > 30) {
            Functions::message((string) __('game/alliance.al_name_required'), $makeUrl, 3);
        }

        if ($this->allianceNameExists($name) !== null) {
            Functions::message(strtr((string) __('game/alliance.al_name_already_exists'), ['%s' => $name]), $makeUrl, 3);
        }

        $ranks = strtr(
            '[{"rank":"Founder","rights":{"1":1,"2":1,"3":1,"4":1,"5":1,"6":1,"7":1,"8":1,"9":1}},'
            . '{"rank":"Newcomer","rights":{"1":0,"2":0,"3":0,"4":0,"5":0,"6":0,"7":0,"8":0,"9":0}}]',
            [
                'Founder' => (string) __('game/alliance.al_founder_rank_text'),
                'Newcomer' => (string) __('game/alliance.al_new_member_rank_text'),
            ]
        );

        // The alliance name and tag are bound as parameters; the legacy code
        // interpolated them straight into the INSERT.
        DB::transaction(function () use ($name, $tag, $ranks): void {
            DB::insert(
                $this->prepareSql(
                    'INSERT INTO `' . ALLIANCE . '`
                        SET `alliance_name` = ?, `alliance_tag` = ?, `alliance_owner` = ?,
                            `alliance_register_time` = ?, `alliance_ranks` = ?;'
                ),
                [$name, $tag, $this->userInt('id'), time(), $ranks]
            );

            $newAllyId = (int) DB::getPdo()->lastInsertId();

            DB::insert(
                $this->prepareSql('INSERT INTO `' . ALLIANCE_STATISTICS . '` SET `alliance_statistic_alliance_id` = ?;'),
                [$newAllyId]
            );

            DB::update(
                $this->prepareSql('UPDATE `' . USERS . '` SET `ally_id` = ?, `ally_register_time` = ? WHERE `id` = ?;'),
                [$newAllyId, time(), $this->userInt('id')]
            );
        });

        $message = str_replace(['%s', '%d'], [$name, $tag], (string) __('game/alliance.al_created'));

        Functions::messageBox($message, $message . '<br><br>', 'game.php?page=alliance', (string) __('game/alliance.al_continue'));
    }

    private function applySection(Request $request): View
    {
        $current = $this->alliance->getCurrentAlliance();

        if ($current->getAllianceRequestNotAllow() === 0) {
            Functions::message((string) __('game/alliance.al_alliance_closed'), 'game.php?page=alliance', 3);
        }

        $text = is_string($raw = $request->input('text')) ? $raw : '';

        if ($request->filled('send') && $text !== '') {
            // The request text is bound; the legacy code interpolated it.
            DB::update(
                $this->prepareSql(
                    'UPDATE `' . USERS . '`
                        SET `ally_request` = ?, `ally_request_text` = ?, `ally_register_time` = ?, `ally_rank_id` = 1
                    WHERE `id` = ?;'
                ),
                [$this->allianceId($request), $text, time(), $this->userInt('id')]
            );

            Functions::message((string) __('game/alliance.al_request_confirmation_message'), 'game.php?page=alliance', 3);
        }

        return view('alliance.apply', [
            'allyid' => $this->allianceId($request),
            'text_apply' => $current->getAllianceRequest() !== ''
                ? $current->getAllianceRequest()
                : (string) __('game/alliance.al_default_request_text'),
            'write_to_alliance' => strtr((string) __('game/alliance.al_write_request'), ['%s' => $current->getAllianceTag()]),
        ]);
    }

    private function memberslistSection(Request $request): View | RedirectResponse
    {
        if (!$this->alliance->hasAccess(AllianceRanks::VIEW_MEMBER_LIST)) {
            return redirect('game.php?page=alliance');
        }

        $sortOrder = $request->integer('sort2');

        $members = array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql(
                    'SELECT u.`id`, u.`onlinetime`, u.`name`, u.`galaxy`, u.`system`, u.`planet`,
                        u.`ally_register_time`, u.`ally_rank_id`, s.`user_statistic_total_points`
                    FROM `' . USERS . '` AS u
                    INNER JOIN `' . USERS_STATISTICS . '` AS s ON u.`id` = s.`user_statistic_user_id`
                    WHERE u.`ally_id` = ?' . $this->membersSort($request->integer('sort1'), $sortOrder) . ';'
                ),
                [$this->userInt('ally_id')]
            )
        );

        $list = [];
        $position = 0;

        foreach ($members as $member) {
            $position++;
            $list[] = [
                'position' => $position,
                'name' => $this->asString($member['name'] ?? ''),
                'id' => $this->asInt($member['id'] ?? 0),
                'write_message' => (string) __('game/global.write_message'),
                'ally_range' => $this->userRank($this->asInt($member['id'] ?? 0), $this->asInt($member['ally_rank_id'] ?? 0)),
                'points' => $this->formatService->prettyNumber($this->asInt($member['user_statistic_total_points'] ?? 0)),
                'galaxy' => $this->asInt($member['galaxy'] ?? 0),
                'system' => $this->asInt($member['system'] ?? 0),
                'coords' => $this->formatService->prettyCoords(
                    $this->asInt($member['galaxy'] ?? 0),
                    $this->asInt($member['system'] ?? 0),
                    $this->asInt($member['planet'] ?? 0)
                ),
                'ally_register_time' => $this->timingService->formatExtendedDate($this->asInt($member['ally_register_time'] ?? 0)),
                'online_time' => $this->alliance->hasAccess(AllianceRanks::ONLINE_STATUS)
                    ? $this->timingService->getOnlineStatus($this->asInt($member['onlinetime'] ?? 0), time())
                    : '-',
            ];
        }

        $orderRules = [1 => 2, 2 => 1];

        return view('alliance.members', [
            'total' => $position,
            's' => $orderRules[$sortOrder] ?? 1,
            'list_of_members' => $list,
        ]);
    }

    private function membersSort(int $field, int $order): string
    {
        $column = match ($field) {
            1 => '`name`',
            2 => '`ally_rank_id`',
            3 => '`user_statistic_total_points`',
            4 => '`ally_register_time`',
            5 => '`onlinetime`',
            default => '`id`',
        };

        $direction = match ($order) {
            1 => ' DESC',
            2 => ' ASC',
            default => '',
        };

        return ' ORDER BY ' . $column . $direction;
    }

    private function circularSection(Request $request): View | RedirectResponse
    {
        if (!$this->alliance->hasAccess(AllianceRanks::SEND_CIRCULAR)) {
            return redirect('game.php?page=alliance');
        }

        if ($request->integer('sendmail') !== 0) {
            $this->sendCircular($request);
        }

        $ranks = [];

        foreach ($this->alliance->getCurrentAllianceRankObject()->getAllRanksAsArray() as $id => $rank) {
            if (is_array($rank) && isset($rank['rank']) && is_scalar($rank['rank'])) {
                $ranks[] = ['value' => $this->asInt($id) + 1, 'name' => (string) $rank['rank']];
            }
        }

        return view('alliance.circular', ['ranks_list' => $ranks]);
    }

    private function sendCircular(Request $request): void
    {
        $rankFilter = $request->integer('r');
        $text = is_string($raw = $request->input('text')) ? $raw : '';
        $allyId = $this->userInt('ally_id');

        $members = $rankFilter === 0
            ? $this->rows('SELECT `id`, `name`, `ally_rank_id` FROM `' . USERS . '` WHERE `ally_id` = ?;', [$allyId])
            : $this->rows('SELECT `id`, `name` FROM `' . USERS . '` WHERE `ally_id` = ? AND `ally_rank_id` = ?;', [$allyId, $rankFilter]);

        $names = [];

        foreach ($members as $member) {
            Functions::sendMessage(
                $this->asInt($member['id'] ?? 0),
                $this->userInt('id'),
                0,
                3,
                $this->currentAllianceTag(),
                $this->asString($this->user['name'] ?? ''),
                $text
            );

            $names[] = $this->asString($member['name'] ?? '');
        }

        Functions::messageBox(
            (string) __('game/alliance.al_circular_sended'),
            implode('<br>', $names),
            'game.php?page=alliance',
            (string) __('game/alliance.al_continue'),
            true
        );
    }

    private function exitSection(Request $request): RedirectResponse
    {
        if ($this->alliance->isOwner()) {
            Functions::message((string) __('game/alliance.al_founder_cant_leave_alliance'), 'game.php?page=alliance', 3);
        }

        if ($request->integer('yes') !== 0) {
            DB::update(
                $this->prepareSql('UPDATE `' . USERS . '` SET `ally_id` = 0, `ally_rank_id` = 0 WHERE `id` = ? AND `ally_id` = ?;'),
                [$this->userInt('id'), $this->allianceId($request)]
            );

            Functions::messageBox(
                strtr((string) __('game/alliance.al_leave_sucess'), ['%s' => $this->alliance->getCurrentAlliance()->getAllianceName()]),
                '<br>',
                'game.php?page=alliance',
                (string) __('game/alliance.al_continue')
            );
        }

        Functions::messageBox(
            strtr((string) __('game/alliance.al_do_you_really_want_to_go_out'), ['%s' => $this->alliance->getCurrentAlliance()->getAllianceName()]),
            '<br>',
            'game.php?page=alliance&mode=exit&yes=1',
            (string) __('game/alliance.al_go_out_yes')
        );

        // Unreachable: messageBox() renders and short-circuits via LegacyView.
        return redirect('game.php?page=alliance');
    }

    private function allianceNameExists(string $name): ?string
    {
        $row = DB::selectOne(
            $this->prepareSql('SELECT `alliance_name` FROM `' . ALLIANCE . '` WHERE `alliance_name` = ?;'),
            [$name]
        );

        return is_object($row) ? $this->asString(get_object_vars($row)['alliance_name'] ?? null) : null;
    }

    private function allianceTagExists(string $tag): ?string
    {
        $row = DB::selectOne(
            $this->prepareSql('SELECT `alliance_tag` FROM `' . ALLIANCE . '` WHERE `alliance_tag` = ?;'),
            [$tag]
        );

        return is_object($row) ? $this->asString(get_object_vars($row)['alliance_tag'] ?? null) : null;
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

    private function publicRequestsBlock(): string
    {
        if (
            $this->userInt('ally_id') === 0
            && $this->userInt('ally_request') === 0
            && $this->alliance->getCurrentAlliance()->getAllianceRequestNotAllow() !== 0
        ) {
            $url = $this->formatService->link(
                'game.php?page=alliance&mode=apply&allyid=' . $this->alliance->getCurrentAlliance()->getAllianceId(),
                (string) __('game/alliance.al_click_to_send_request'),
                (string) __('game/alliance.al_click_to_send_request')
            );

            return '<tr><th scope="row">' . __('game/alliance.al_request') . '</th><th role="cell">' . $url . '</th></tr>';
        }

        return '';
    }

    private function imageBlock(): string
    {
        $image = $this->alliance->getCurrentAlliance()->getAllianceImage();

        if ($image !== '') {
            return '<tr><th role="cell" colspan="2">' . Functions::setImage($image, $image) . '</th></tr>';
        }

        return '';
    }

    /**
     * @return array{detail_title: string, detail_content: string}
     */
    private function tagBlock(): array
    {
        return [
            'detail_title' => (string) __('game/alliance.al_ally_info_tag'),
            'detail_content' => $this->alliance->getCurrentAlliance()->getAllianceTag(),
        ];
    }

    /**
     * @return array{detail_title: string, detail_content: string}
     */
    private function nameBlock(): array
    {
        return [
            'detail_title' => (string) __('game/alliance.al_ally_info_name'),
            'detail_content' => $this->alliance->getCurrentAlliance()->getAllianceName(),
        ];
    }

    /**
     * @return array{detail_title: string, detail_content: string}
     */
    private function membersBlock(): array
    {
        $link = '';

        if ($this->alliance->hasAccess(AllianceRanks::VIEW_MEMBER_LIST)) {
            $link = ' (' . $this->formatService->link('game.php?page=alliance&mode=memberslist', (string) __('game/alliance.al_user_list')) . ')';
        }

        return [
            'detail_title' => (string) __('game/alliance.al_ally_info_members'),
            'detail_content' => $this->asString($this->alliance->getCurrentAlliance()->getAllianceMembers()) . $link,
        ];
    }

    /**
     * @return array{detail_title: string, detail_content: string}
     */
    private function rankBlock(): array
    {
        $rank = $this->userRank($this->userInt('id'), $this->userInt('ally_rank_id'));
        $admin = '';

        if ($this->alliance->hasAccess(AllianceRanks::ADMINISTRATION)) {
            $admin = ' (' . $this->formatService->link('game.php?page=alliance&mode=admin&edit=ally', (string) __('game/alliance.al_manage_alliance')) . ')';
        }

        return [
            'detail_title' => (string) __('game/alliance.al_rank'),
            'detail_content' => $rank . $admin,
        ];
    }

    /**
     * @return array{detail_title: string, detail_content: string}
     */
    private function requestsBlock(): array
    {
        $content = '';

        $row = DB::selectOne(
            $this->prepareSql('SELECT COUNT(id) AS total_requests FROM `' . USERS . '` WHERE `ally_request` = ?;'),
            [$this->alliance->getCurrentAlliance()->getAllianceId()]
        );

        $count = is_object($row) ? $this->asInt(get_object_vars($row)['total_requests'] ?? 0) : 0;

        if ($this->alliance->hasAccess(AllianceRanks::APPLICATION_MANAGEMENT) && $count !== 0) {
            $content = $this->formatService->link(
                'game.php?page=alliance&mode=admin&edit=requests',
                $count . ' ' . __('game/alliance.al_new_requests')
            );
        }

        return [
            'detail_title' => (string) __('game/alliance.al_requests'),
            'detail_content' => $content,
        ];
    }

    /**
     * @return array{detail_title: string, detail_content: string}
     */
    private function circularBlock(): array
    {
        if (!$this->alliance->hasAccess(AllianceRanks::SEND_CIRCULAR)) {
            return ['detail_title' => '', 'detail_content' => ''];
        }

        return [
            'detail_title' => (string) __('game/alliance.al_circular_message'),
            'detail_content' => $this->formatService->link('game.php?page=alliance&mode=circular', (string) __('game/alliance.al_send_circular_message')),
        ];
    }

    private function descriptionBlock(): string
    {
        $description = (string) __('game/alliance.al_description_message');
        $current = $this->alliance->getCurrentAlliance()->getAllianceDescription();

        if ($current !== '') {
            $description = nl2br($this->bbcode->bbCode($current)) . '</th></tr>';
        }

        return '<tr><th role="cell" colspan="2" height="100px">' . $description . '</th></tr>';
    }

    private function webBlock(): string
    {
        $webUrl = $this->alliance->getCurrentAlliance()->getAllianceWeb();

        if ($webUrl === '') {
            return '-';
        }

        $url = UrlHelper::prepUrl($webUrl);

        return $this->formatService->link($url, $url, $url, 'target="_blank"');
    }

    private function textBlock(): string
    {
        return nl2br($this->bbcode->bbCode($this->alliance->getCurrentAlliance()->getAllianceText()));
    }

    private function userRank(int $memberId, int $memberRankId): string
    {
        $rank = $this->alliance->getCurrentAllianceRankObject()->getRankById($memberRankId);

        if (isset($rank['rank']) && is_scalar($rank['rank'])) {
            return (string) $rank['rank'];
        }

        return $this->alliance->getCurrentAlliance()->getAllianceOwner() === $memberId
            ? (string) __('game/alliance.al_founder_rank_text')
            : (string) __('game/alliance.al_new_member_rank_text');
    }

    private function currentAllianceTag(): string
    {
        return $this->alliance->getCurrentAlliance()->getAllianceTag();
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
