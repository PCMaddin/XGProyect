<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Messenger;

use App\Libraries\Messenger\Messenger;
use App\Libraries\Messenger\MessagesOptions;
use App\Models\Messages;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;
use Xgp\App\Core\Enumerators\MessagesEnumerator;

#[CoversClass(Messenger::class)]
class MessengerTest extends DatabaseTestCase
{
    public function testSendMessageWritesTheRowWithItsCategory(): void
    {
        $options = new MessagesOptions();
        $options->setTo(7);
        $options->setSender(3);
        $options->setType(MessagesEnumerator::COMBAT);
        $options->setFrom('Fleet Command');
        $options->setSubject('Combat report');
        $options->setMessageText('You won.');

        (new Messenger())->sendMessage($options);

        $row = Messages::query()->where('message_receiver', 7)->first();

        $this->assertNotNull($row);
        // the type reaches the row (legacy filed everything as GENERAL)
        $this->assertSame(MessagesEnumerator::COMBAT, $row->message_type);
        $this->assertSame('Combat report', $row->message_subject);
        $this->assertSame(3, $row->message_sender);
    }

    public function testDangerousSubjectIsStoredLiterallyNotExecuted(): void
    {
        $options = new MessagesOptions();
        $options->setTo(8);
        $options->setSubject("'); DROP TABLE messages;--");
        $options->setMessageText('x');

        (new Messenger())->sendMessage($options);

        // the injection payload is bound, so it survives as data and the table is intact
        $this->assertSame("'); DROP TABLE messages;--", Messages::query()->where('message_receiver', 8)->value('message_subject'));
        $this->assertSame(1, Messages::query()->count());
    }
}
