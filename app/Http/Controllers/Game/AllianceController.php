<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
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

    public function __construct(private FormatService $formatService)
    {
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
