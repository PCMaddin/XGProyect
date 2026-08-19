<?php

declare(strict_types=1);

namespace App\Libraries\Messenger;

use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;

/**
 * Writes an in-game message row. Every field is now bound; the legacy version
 * interpolated the (user-controlled) from/subject/text straight into the SQL.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
final class Messenger
{
    use PreparesLegacySql;

    public function sendMessage(MessagesOptions $options): void
    {
        DB::insert(
            $this->prepareSql(
                'INSERT INTO `' . MESSAGES . '`
                    (`message_receiver`, `message_sender`, `message_time`, `message_type`,
                     `message_from`, `message_subject`, `message_text`)
                    VALUES (?, ?, ?, ?, ?, ?, ?);'
            ),
            [
                $options->getTo(),
                $options->getSender(),
                $options->getTime(),
                $options->getType(),
                $options->getFrom(),
                $options->getSubject(),
                $options->getMessageText(),
            ]
        );
    }
}
