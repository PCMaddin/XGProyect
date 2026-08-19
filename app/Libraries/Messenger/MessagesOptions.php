<?php

declare(strict_types=1);

namespace App\Libraries\Messenger;

use Xgp\App\Core\Enumerators\MessagesEnumerator;
use Xgp\App\Helpers\StringsHelper;

/**
 * Value object for a single in-game message.
 *
 * The legacy getType() always returned GENERAL because it tested is_object()
 * on a typed int property; the type is now stored and returned directly (with
 * a GENERAL default), so the caller-selected category is actually applied.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
final class MessagesOptions
{
    private int $to = 0;

    private int $sender = 0;

    private int $time = 0;

    private int $type = MessagesEnumerator::GENERAL;

    private string $from = '';

    private string $subject = '';

    private string $messageText = '';

    private int $messageFormat = MessagesFormat::SIMPLE;

    public function getTo(): int
    {
        return $this->to;
    }

    public function getSender(): int
    {
        return $this->sender;
    }

    public function getTime(): int
    {
        return $this->time === 0 ? time() : $this->time;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getMessageText(): string
    {
        return $this->messageText;
    }

    public function getMessageFormat(): int
    {
        return $this->messageFormat;
    }

    public function setTo(int $to): void
    {
        $this->to = $to;
    }

    public function setSender(int $sender): void
    {
        $this->sender = $sender;
    }

    public function setTime(int $time): void
    {
        $this->time = $time;
    }

    public function setType(int $type): void
    {
        $this->type = $type;
    }

    public function setFrom(string $from): void
    {
        $this->from = $from;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }

    public function setMessageText(string $messageText): void
    {
        $this->messageText = $this->messageFormat === MessagesFormat::HTML
            ? stripslashes($messageText)
            : StringsHelper::escapeString($messageText);
    }

    public function setMessageFormat(int $messageFormat): void
    {
        $this->messageFormat = $messageFormat;
    }
}
