<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\SwitchIntEnumerator as SwitchInt;
use App\Libraries\Functions;
use Xgp\App\Libraries\Users;

/**
 * Message inbox: default and premium (categorised) views, address books and
 * bulk delete actions.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class MessagesController extends BaseController
{
    use PreparesLegacySql;

    private const REDIRECT_BASE = 'game.php?';

    private const MESSAGE_ICON = 'assets/upload/skins/xgproyect/img/m.gif';

    private const DELETE_ACTIONS = ['deleteall', 'deletemarked', 'deleteunmarked', 'deleteallshown'];

    /** Message type id => checkbox field name used by the premium view. */
    private const TYPE_NAMES = [
        0 => 'espioopen',
        1 => 'combatopen',
        2 => 'expopen',
        3 => 'allyopen',
        4 => 'useropen',
        5 => 'generalopen',
    ];

    /** @var array<string, mixed> */
    private array $user = [];

    public function __construct(private OfficerService $officerService)
    {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Messages));

        $this->user = Users::getInstance()->getUserData();

        if (in_array($request->input('deletemessages'), self::DELETE_ACTIONS, true)) {
            return $this->doDelete($request);
        }

        if ($this->officerService->isOfficerActive($this->userInt('premium_officier_commander'), time())) {
            return $this->premiumSection($request);
        }

        return $this->defaultSection();
    }

    private function defaultSection(): View
    {
        $userId = $this->userId();
        $messages = [];

        if ($userId > 0) {
            DB::update(
                $this->prepareSql('UPDATE `' . MESSAGES . '` SET `message_read` = 1 WHERE `message_receiver` = ?;'),
                [$userId]
            );

            $messages = $this->rows(
                'SELECT * FROM `' . MESSAGES . '` WHERE `message_receiver` = ? ORDER BY `message_time` DESC;',
                [$userId]
            );
        }

        return view('messages.default', [
            'message_list' => $this->buildMessagesList($messages),
            'operators_list' => $this->getOperatorsAddressBook(),
        ]);
    }

    private function premiumSection(Request $request): View
    {
        $active = [];
        $messages = false;
        $messagesList = [];
        $deleteOptions = false;

        if ($request->integer('dsp') === 1) {
            $typeIds = $this->selectedTypeIds($request);
            $active = array_fill_keys($typeIds, 1);
            $messages = true;
            $deleteOptions = true;
            $userId = $this->userId();

            if ($userId > 0 && $typeIds !== []) {
                $placeholders = implode(',', array_fill(0, count($typeIds), '?'));

                $messagesList = $this->buildMessagesList($this->rows(
                    'SELECT * FROM `' . MESSAGES . '`
                    WHERE `message_receiver` = ? AND `message_type` IN (' . $placeholders . ')
                    ORDER BY `message_time` DESC;',
                    array_merge([$userId], $typeIds)
                ));

                DB::update(
                    $this->prepareSql(
                        'UPDATE `' . MESSAGES . '` SET `message_read` = 1
                        WHERE `message_receiver` = ? AND `message_type` IN (' . $placeholders . ');'
                    ),
                    array_merge([$userId], $typeIds)
                );
            }
        }

        return view('messages.premium', array_merge(
            [
                'form_submit' => self::REDIRECT_BASE . ($request->getQueryString() ?? ''),
                'message_type_list' => $this->getMessagesTypesList($active),
                'messages' => $messages,
                'messages_list' => $messagesList,
                'deleteOptions' => $deleteOptions,
            ],
            $this->getExtraBlocksDisplay($request)
        ));
    }

    /**
     * @return array<int, int>
     */
    private function selectedTypeIds(Request $request): array
    {
        $ids = [];

        foreach (array_keys($request->query()) as $field) {
            $typeId = array_search($field, self::TYPE_NAMES, true);

            if ($typeId !== false) {
                $ids[] = $typeId;
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMessagesList(array $messages): array
    {
        $format = strtr(app(SettingsService::class)->getString('date_format_extended'), ['.Y' => '']);
        $list = [];

        foreach ($messages as $message) {
            $list[] = [
                'message_id' => $this->asInt($message['message_id'] ?? 0),
                'message_time' => date($format, $this->asInt($message['message_time'] ?? 0)),
                'message_from' => $this->asString($message['message_from'] ?? ''),
                'message_subject' => $this->asString($message['message_subject'] ?? ''),
                'message_text' => nl2br($this->asString($message['message_text'] ?? '')),
                'message_reply' => $this->setMessageReply($this->asInt($message['message_sender'] ?? 0)),
            ];
        }

        return $list;
    }

    private function setMessageReply(int $from): string
    {
        if ($from <= 0) {
            return '';
        }

        return app(FormatService::class)->link(
            'game.php?page=chat&playerId=' . $from,
            Functions::setImage(asset(self::MESSAGE_ICON), (string) __('game/messages.mg_send_message')),
            (string) __('game/messages.mg_send_message')
        );
    }

    /**
     * @param  array<int, int>  $active
     *
     * @return array<int, array<string, mixed>>
     */
    private function getMessagesTypesList(array $active): array
    {
        $userId = $this->userId();

        if ($userId <= 0) {
            return [];
        }

        $rows = $this->rows(
            'SELECT `message_type`,
                COUNT(`message_type`) AS message_type_count,
                SUM(`message_read` = 0) AS unread_count
            FROM `' . MESSAGES . '`
            WHERE `message_receiver` = ?
            GROUP BY `message_type`;',
            [$userId]
        );

        $list = [];

        foreach ($rows as $row) {
            $typeId = $this->asInt($row['message_type'] ?? -1);

            if (!isset(self::TYPE_NAMES[$typeId])) {
                continue;
            }

            $list[] = [
                'message_type' => self::TYPE_NAMES[$typeId],
                'checked' => isset($active[$typeId]) ? 'checked' : '',
                'checked_status' => isset($active[$typeId]) ? SwitchInt::on : SwitchInt::off,
                'message_type_name' => $this->typeLabel($typeId),
                'message_amount' => $this->asInt($row['message_type_count'] ?? 0),
                'message_unread' => $this->asInt($row['unread_count'] ?? 0),
            ];
        }

        return $list;
    }

    private function typeLabel(int $typeId): string
    {
        $labels = trans('game/messages.mg_type');

        if (is_array($labels) && isset($labels[$typeId]) && is_scalar($labels[$typeId])) {
            return (string) $labels[$typeId];
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function getExtraBlocksDisplay(Request $request): array
    {
        $counts = $this->addressBookCounts();

        $result = [
            'owncontactsopen' => '',
            'buddys_count' => $this->asInt($counts['buddys_count'] ?? 0),
            'buddy_list' => [],
            'ownallyopen' => '',
            'alliance_count' => $this->asInt($counts['alliance_count'] ?? 0),
            'members_list' => [],
            'gameoperatorsopen' => '',
            'operators_count' => $this->asInt($counts['operators_count'] ?? 0),
            'operators_list' => [],
            'noticesopen' => '',
            'notes_count' => $this->asInt($counts['notes_count'] ?? 0),
            'notes_list' => [],
        ];

        $blocks = [
            'owncontactsopen' => fn (): array => ['buddy_list' => $this->getFriendsAddressBook()],
            'ownallyopen' => fn (): array => ['members_list' => $this->getAllianceAddressBook()],
            'gameoperatorsopen' => fn (): array => ['operators_list' => $this->getOperatorsAddressBook()],
            'noticesopen' => fn (): array => ['notes_list' => $this->getNotesList()],
        ];

        foreach ($blocks as $key => $loader) {
            if ($request->input($key) === 'on') {
                $result = array_merge($result, $loader(), [$key => 'checked="1"']);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function addressBookCounts(): array
    {
        $userId = $this->userId();
        $allyId = $this->userInt('ally_id');

        if ($userId <= 0 || $allyId < 0) {
            return [];
        }

        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT
                    (SELECT COUNT(`id`) FROM `' . USERS . '`
                        WHERE `ally_id` = ? AND `ally_id` <> 0 AND `id` <> ?) AS alliance_count,
                    (SELECT COUNT(`buddy_id`) FROM `' . BUDDY . '`
                        WHERE `buddy_sender` = ? OR `buddy_receiver` = ?) AS buddys_count,
                    (SELECT COUNT(`note_id`) FROM `' . NOTES . '`
                        WHERE `note_owner` = ?) AS notes_count,
                    (SELECT COUNT(`id`) FROM `' . USERS . '`
                        WHERE `authlevel` <> 0 AND `id` <> ?) AS operators_count;'
            ),
            [$allyId, $userId, $userId, $userId, $userId, $userId]
        );

        return is_object($row) ? get_object_vars($row) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getOperatorsAddressBook(): array
    {
        $userId = $this->userId();

        if ($userId <= 0) {
            return [];
        }

        $rows = $this->rows(
            'SELECT `name`, `email` FROM `' . USERS . '` WHERE `authlevel` > 0 AND `id` <> ?;',
            [$userId]
        );

        return array_map(fn (array $row): array => [
            'name' => $this->asString($row['name'] ?? ''),
            'email' => $this->asString($row['email'] ?? ''),
        ], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getFriendsAddressBook(): array
    {
        $userId = $this->userId();

        if ($userId <= 0) {
            return [];
        }

        $rows = $this->rows(
            'SELECT u.`id`, u.`name`, u.`email`
            FROM `' . BUDDY . '` b
            LEFT JOIN `' . USERS . '` u ON u.`id` = IF(b.`buddy_sender` = ?, b.`buddy_receiver`, b.`buddy_sender`)
            WHERE b.`buddy_sender` = ? OR b.`buddy_receiver` = ?;',
            [$userId, $userId, $userId]
        );

        return array_map(fn (array $row): array => [
            'name' => $this->asString($row['name'] ?? ''),
            'id' => $this->asInt($row['id'] ?? 0),
        ], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAllianceAddressBook(): array
    {
        $userId = $this->userId();
        $allyId = $this->userInt('ally_id');

        if ($userId <= 0 || $allyId <= 0) {
            return [];
        }

        $rows = $this->rows(
            'SELECT `id`, `name`, `email` FROM `' . USERS . '` WHERE `ally_id` = ? AND `id` <> ?;',
            [$allyId, $userId]
        );

        return array_map(fn (array $row): array => [
            'name' => $this->asString($row['name'] ?? ''),
            'id' => $this->asInt($row['id'] ?? 0),
        ], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getNotesList(): array
    {
        $userId = $this->userId();

        if ($userId <= 0) {
            return [];
        }

        $rows = $this->rows(
            'SELECT `note_id`, `note_priority`, `note_title` FROM `' . NOTES . '` WHERE `note_owner` = ?;',
            [$userId]
        );

        return array_map(fn (array $row): array => [
            'note_id' => $this->asInt($row['note_id'] ?? 0),
            'note_color' => $this->noteColor($this->asInt($row['note_priority'] ?? 0)),
            'note_title' => $this->asString($row['note_title'] ?? ''),
        ], $rows);
    }

    private function noteColor(int $priority): string
    {
        return match ($priority) {
            0 => 'lime',
            1 => 'yellow',
            default => 'red',
        };
    }

    private function doDelete(Request $request): RedirectResponse
    {
        $userId = $this->userId();

        if ($userId > 0) {
            match ($request->input('deletemessages')) {
                'deleteall' => DB::delete(
                    $this->prepareSql('DELETE FROM `' . MESSAGES . '` WHERE `message_receiver` = ?;'),
                    [$userId]
                ),
                'deletemarked' => $this->deleteByIds($this->markedIds($request), $userId),
                'deleteunmarked' => $this->deleteByIds($this->unmarkedIds($request), $userId),
                'deleteallshown' => $this->deleteShownType($request, $userId),
                default => null,
            };
        }

        return redirect(self::REDIRECT_BASE . str_replace('&amp;', '&', $request->getQueryString() ?? ''));
    }

    /**
     * @return array<int, int>
     */
    private function markedIds(Request $request): array
    {
        $ids = [];

        /** @var array<string, mixed> $payload */
        $payload = $request->post();

        foreach ($payload as $key => $value) {
            $id = $this->parseMessageId((string) $key, 'delmes');

            if ($id !== null && $value === 'on') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    private function unmarkedIds(Request $request): array
    {
        $ids = [];

        /** @var array<string, mixed> $payload */
        $payload = $request->post();

        foreach (array_keys($payload) as $key) {
            $id = $this->parseMessageId((string) $key, 'showmes');

            if ($id !== null && !array_key_exists('delmes' . $id, $payload)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Extracts a positive message id from a checkbox field name, or null when
     * the suffix is not a positive integer. This keeps non-numeric field
     * suffixes (a legacy SQL-injection vector) out of the delete query.
     */
    private function parseMessageId(string $key, string $prefix): ?int
    {
        if (!str_starts_with($key, $prefix)) {
            return null;
        }

        $suffix = substr($key, strlen($prefix));

        return is_numeric($suffix) && (int) $suffix > 0 ? (int) $suffix : null;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function deleteByIds(array $ids, int $userId): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        DB::delete(
            $this->prepareSql(
                'DELETE FROM `' . MESSAGES . '` WHERE `message_id` IN (' . $placeholders . ') AND `message_receiver` = ?;'
            ),
            array_merge($ids, [$userId])
        );
    }

    private function deleteShownType(Request $request, int $userId): void
    {
        if ($request->integer('dsp') !== 1) {
            return;
        }

        $typeIds = $this->selectedTypeIds($request);

        if ($typeIds === []) {
            return;
        }

        DB::delete(
            $this->prepareSql(
                'DELETE FROM `' . MESSAGES . '` WHERE `message_type` = ? AND `message_receiver` = ?;'
            ),
            [$typeIds[0], $userId]
        );
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

    private function userId(): int
    {
        return $this->userInt('id');
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
