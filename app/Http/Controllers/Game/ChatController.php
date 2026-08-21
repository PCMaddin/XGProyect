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
use App\Libraries\Functions;
use Xgp\App\Libraries\Users;

/**
 * Compose and send a private message to another player.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class ChatController extends BaseController
{
    use PreparesLegacySql;

    /** Message type handled by Functions::sendMessage for player-to-player mail. */
    private const MESSAGE_TYPE_PLAYER = 4;

    private const COLOR_SUCCESS = '#00FF00';
    private const COLOR_ERROR = '#FF0000';

    /** @var array<string, mixed> */
    private array $user = [];

    public function __construct(private FormatService $formatService)
    {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Messages));

        $this->user = Users::getInstance()->getUserData();

        $receiverId = $request->integer('playerId');
        $receiver = $this->findReceiver($receiverId);

        if ($receiver === null) {
            return redirect('game.php?page=messages');
        }

        $state = $request->isMethod('post')
            ? $this->handleSubmission($request, $receiverId)
            : ['subject' => '', 'text' => '', 'error_text' => '', 'error_color' => ''];

        return view('chat.view', [
            'id' => $this->int($receiver, 'id'),
            'to' => $this->str($receiver, 'name') . ' ' . $this->formatService->prettyCoords(
                $this->int($receiver, 'planet_galaxy'),
                $this->int($receiver, 'planet_system'),
                $this->int($receiver, 'planet_planet')
            ),
            'subject' => $state['subject'],
            'text' => $state['text'],
            'error_text' => $state['error_text'],
            'error_color' => $state['error_color'],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findReceiver(int $receiverId): ?array
    {
        if ($receiverId <= 0) {
            return null;
        }

        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT
                    u.`id`,
                    u.`name`,
                    p.`planet_galaxy`,
                    p.`planet_system`,
                    p.`planet_planet`
                FROM `' . PLANETS . '` AS p
                INNER JOIN `' . USERS . '` AS u ON p.`planet_user_id` = u.`id`
                WHERE p.`planet_user_id` = ?;'
            ),
            [$receiverId]
        );

        return is_object($row) ? get_object_vars($row) : null;
    }

    /**
     * @return array{subject: string, text: string, error_text: string, error_color: string}
     */
    private function handleSubmission(Request $request, int $receiverId): array
    {
        $rawSubject = $request->input('subject');
        $rawText = $request->input('text');

        $subject = is_string($rawSubject) ? trim($rawSubject) : '';
        $text = is_string($rawText) ? trim($rawText) : '';

        $missing = $this->missingField($subject, $text);

        if ($missing !== null) {
            return [
                'subject' => $subject,
                'text' => $text,
                'error_text' => __($missing === 'subject' ? 'game/chat.pm_no_subject' : 'game/chat.pm_no_text'),
                'error_color' => self::COLOR_ERROR,
            ];
        }

        Functions::sendMessage(
            $receiverId,
            $this->int($this->user, 'id'),
            0,
            self::MESSAGE_TYPE_PLAYER,
            $this->str($this->user, 'name') . ' ' . $this->formatService->prettyCoords(
                $this->int($this->user, 'galaxy'),
                $this->int($this->user, 'system'),
                $this->int($this->user, 'planet')
            ),
            $subject,
            $text
        );

        return [
            'subject' => '',
            'text' => '',
            'error_text' => __('game/chat.pm_msg_sended'),
            'error_color' => self::COLOR_SUCCESS,
        ];
    }

    /**
     * Returns the name of the first required field that is empty, or null when both are present.
     */
    private function missingField(string $subject, string $text): ?string
    {
        if ($subject === '') {
            return 'subject';
        }

        if ($text === '') {
            return 'text';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
