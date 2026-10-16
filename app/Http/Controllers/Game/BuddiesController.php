<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Support\Concerns\HydratesModels;
use App\Enums\Module;
use App\Services\TimingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use App\Core\Concerns\PreparesSql;
use App\Models\Buddys;
use App\Core\Enumerators\BuddiesStatusEnumerator as BuddiesStatus;
use App\Libraries\Buddies\Buddy;
use App\Libraries\Functions;
use App\Libraries\Users;

/**
 * Buddy list and buddy requests (send / accept / decline / cancel).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class BuddiesController extends BaseController
{
    use HydratesModels;

    use PreparesSql;

    private const REDIRECT_TARGET = 'game.php?page=buddies';

    /** Message type for buddy notifications (Functions::sendMessage GENERAL). */
    private const MESSAGE_TYPE = 5;

    /** @var array<string, mixed> */
    private array $user = [];

    private Buddy $buddy;

    public function __construct(private TimingService $timingService)
    {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Buddies));

        $this->user = Users::getInstance()->getUserData();
        $this->buddy = $this->loadBuddies();

        $response = $this->runAction($request);

        if ($response !== null) {
            return $response;
        }

        return view('buddies.view', [
            'list_of_requests_received' => $this->mapEntities($this->buddy->getReceivedRequests()),
            'list_of_requests_sent' => $this->mapEntities($this->buddy->getSentRequests()),
            'list_of_buddies' => $this->mapEntities($this->buddy->getBuddies()),
        ]);
    }

    private function loadBuddies(): Buddy
    {
        $userId = $this->userId();

        $rows = array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql(
                    'SELECT * FROM `' . BUDDY . '` WHERE `buddy_sender` = ? OR `buddy_receiver` = ?;'
                ),
                [$userId, $userId]
            )
        );

        return new Buddy($rows, $userId);
    }

    private function runAction(Request $request): View | RedirectResponse | null
    {
        $action = $this->resolveAction($request->integer('mode'), $request->integer('sm'));

        // removing, accepting and sending change something: only by POST
        if ($action !== 'form' && !$request->isMethod('post')) {
            return null;
        }

        return match ($action) {
            'remove' => $this->removeRequest($request),
            'accept' => $this->acceptRequest($request),
            'send' => $this->sendRequest($request),
            'form' => $this->buildRequestForm($request),
            default => null,
        };
    }

    /**
     * Maps the legacy mode/sm request parameters to a concrete action. Replaces
     * the legacy dynamic method dispatch on user-supplied indexes.
     */
    private function resolveAction(int $mode, int $sm): ?string
    {
        if ($mode === 2) {
            return 'form';
        }

        if ($mode === 1) {
            return match ($sm) {
                1 => 'remove',
                2 => 'accept',
                3 => 'send',
                default => null,
            };
        }

        return null;
    }

    private function removeRequest(Request $request): RedirectResponse
    {
        $bid = $request->integer('bid');
        $buddy = $this->findBuddy($bid);

        if ($buddy === null) {
            return redirect(self::REDIRECT_TARGET);
        }

        $target = $this->asInt($buddy->buddy_sender) !== $this->userId()
            ? $this->asInt($buddy->buddy_sender)
            : $this->asInt($buddy->buddy_receiver);

        $this->sendBuddyMessage(
            $target,
            $this->asInt($buddy->buddy_status) === BuddiesStatus::IS_NOT_BUDDY ? 1 : 2
        );

        DB::delete(
            $this->prepareSql(
                'DELETE FROM `' . BUDDY . '`
                WHERE `buddy_id` = ? AND (`buddy_receiver` = ? OR `buddy_sender` = ?);'
            ),
            [$bid, $this->userId(), $this->userId()]
        );

        return redirect(self::REDIRECT_TARGET);
    }

    private function acceptRequest(Request $request): RedirectResponse
    {
        $bid = $request->integer('bid');
        $buddy = $this->findBuddy($bid);

        // only the receiver can accept
        if ($buddy === null || $this->asInt($buddy->buddy_receiver) !== $this->userId()) {
            return redirect(self::REDIRECT_TARGET);
        }

        $this->sendBuddyMessage($this->asInt($buddy->buddy_sender), 3);

        DB::update(
            $this->prepareSql(
                'UPDATE `' . BUDDY . '` SET `buddy_status` = ? WHERE `buddy_id` = ? AND `buddy_receiver` = ?;'
            ),
            [BuddiesStatus::IS_BUDDY, $bid, $this->userId()]
        );

        return redirect(self::REDIRECT_TARGET);
    }

    private function sendRequest(Request $request): RedirectResponse
    {
        $target = $request->integer('user');
        $text = is_string($raw = $request->input('text')) ? $raw : '';

        // the form offers only other, existing players; the posted id is not
        // checked by it
        if (DB::table('users')->where('id', $target)->where('id', '<>', $this->userId())->doesntExist()) {
            return redirect(self::REDIRECT_TARGET);
        }

        if ($this->relationshipExists($target)) {
            Functions::message(__('game/buddies.bu_request_exists'), self::REDIRECT_TARGET, 3, true);
        }

        $this->sendBuddyMessage($target, 4);

        DB::insert(
            $this->prepareSql(
                'INSERT INTO `' . BUDDY . '`
                    SET `buddy_sender` = ?, `buddy_receiver` = ?, `buddy_status` = ?, `buddy_request_text` = ?;'
            ),
            [$this->userId(), $target, BuddiesStatus::IS_NOT_BUDDY, strip_tags($text)]
        );

        return redirect(self::REDIRECT_TARGET);
    }

    private function buildRequestForm(Request $request): View | RedirectResponse
    {
        $target = $request->integer('u');

        if ($target === $this->userId()) {
            Functions::message(__('game/buddies.bu_cannot_request_yourself'), self::REDIRECT_TARGET, 2, true);
        }

        $row = DB::selectOne(
            $this->prepareSql('SELECT `id`, `name` FROM `' . USERS . '` WHERE `id` = ?;'),
            [$target]
        );

        if (!is_object($row)) {
            return redirect(self::REDIRECT_TARGET);
        }

        return view('buddies.request', get_object_vars($row));
    }

    /**
     * A buddy request or relation of the current user, null if there is none.
     *
     * Bugfix: an unknown id passed `false` to the entity (TypeError), and any
     * id was accepted, so a user could send "rejected" / "deleted" /
     * "accepted" messages about other players' requests.
     */
    private function findBuddy(int $bid): ?Buddys
    {
        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT * FROM `' . BUDDY . '`
                WHERE `buddy_id` = ? AND (`buddy_receiver` = ? OR `buddy_sender` = ?);'
            ),
            [$bid, $this->userId(), $this->userId()]
        );

        return is_object($row) ? self::hydrateRow(Buddys::class, get_object_vars($row)) : null;
    }

    private function relationshipExists(int $target): bool
    {
        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT `buddy_id` FROM `' . BUDDY . '`
                WHERE (`buddy_receiver` = ? AND `buddy_sender` = ?)
                    OR (`buddy_receiver` = ? AND `buddy_sender` = ?);'
            ),
            [$this->userId(), $target, $target, $this->userId()]
        );

        return is_object($row) && $this->asInt((self::hydrateRow(Buddys::class, get_object_vars($row)))->buddy_id) !== 0;
    }

    private function sendBuddyMessage(int $to, int $type): void
    {
        $texts = [
            1 => ['title' => 'bu_rejected_title', 'text' => 'bu_rejected_text'],
            2 => ['title' => 'bu_deleted_title', 'text' => 'bu_deleted_text'],
            3 => ['title' => 'bu_accepted_title', 'text' => 'bu_accepted_text'],
            4 => ['title' => 'bu_to_accept_title', 'text' => 'bu_to_accept_text'],
        ];

        if (!isset($texts[$type])) {
            return;
        }

        $name = $this->asString($this->user['name'] ?? '');

        Functions::sendMessage(
            $to,
            $this->userId(),
            0,
            self::MESSAGE_TYPE,
            $name,
            (string) __('game/buddies.' . $texts[$type]['title']),
            str_replace('%u', $name, (string) __('game/buddies.' . $texts[$type]['text']))
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapEntities(mixed $entities): array
    {
        $rows = [];

        if (!is_array($entities)) {
            return $rows;
        }

        foreach ($entities as $entity) {
            if (!$entity instanceof Buddys) {
                continue;
            }

            $data = $this->extractPlayerData($entity);

            if ($data !== null) {
                $rows[] = $data;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractPlayerData(Buddys $buddy): ?array
    {
        $idToGet = $this->asInt($buddy->buddy_sender) === $this->userId()
            ? $this->asInt($buddy->buddy_receiver)
            : $this->asInt($buddy->buddy_sender);

        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT u.`id`, u.`name`, u.`galaxy`, u.`system`, u.`planet`, u.`onlinetime`,
                    a.`alliance_id`, a.`alliance_name`
                FROM `' . USERS . '` AS u
                LEFT JOIN `' . ALLIANCE . '` AS a ON a.`alliance_id` = u.`ally_id`
                WHERE u.`id` = ?;'
            ),
            [$idToGet]
        );

        // The referenced player may have been deleted; skip the orphaned row
        // instead of dereferencing null (the legacy code fatally errored here).
        if (!is_object($row)) {
            return null;
        }

        $data = get_object_vars($row);

        return [
            'id' => $this->asInt($data['id'] ?? 0),
            'username' => $this->asString($data['name'] ?? ''),
            'ally_id' => $this->asInt($data['alliance_id'] ?? 0),
            'alliance_name' => $this->asString($data['alliance_name'] ?? ''),
            'galaxy' => $this->asInt($data['galaxy'] ?? 0),
            'system' => $this->asInt($data['system'] ?? 0),
            'planet' => $this->asInt($data['planet'] ?? 0),
            'text' => $this->setText($buddy, $this->asInt($data['onlinetime'] ?? 0)),
            'action' => $this->setAction($buddy),
        ];
    }

    private function setText(Buddys $buddy, int $onlineTime): string
    {
        if ($this->asInt($buddy->buddy_status) === BuddiesStatus::IS_BUDDY) {
            return $this->timingService->getOnlineStatus($onlineTime, time());
        }

        return e($buddy->buddy_request_text);
    }

    private function setAction(Buddys $buddy): string
    {
        $bid = $this->asInt($buddy->buddy_id);

        if ($this->asInt($buddy->buddy_status) === BuddiesStatus::IS_BUDDY) {
            return $this->generateUrl($bid, 1, (string) __('game/buddies.bu_delete'));
        }

        if ($this->asInt($buddy->buddy_sender) === $this->userId()) {
            return $this->generateUrl($bid, 1, (string) __('game/buddies.bu_cancel_request'));
        }

        return $this->generateUrl($bid, 2, (string) __('game/buddies.bu_accept'))
            . '<br>'
            . $this->generateUrl($bid, 1, (string) __('game/buddies.bu_decline'));
    }

    private function generateUrl(int $buddyId, int $sm, string $text): string
    {
        return '<a data-post href="game.php?page=buddies&mode=1&sm=' . $sm . '&bid=' . $buddyId . '">' . $text . '</a>';
    }

    private function userId(): int
    {
        return $this->asInt($this->user['id'] ?? 0);
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
