<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Messenger;

use App\Libraries\Messenger\MessagesFormat;
use App\Libraries\Messenger\MessagesOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Xgp\App\Core\Enumerators\MessagesEnumerator;

#[CoversClass(MessagesOptions::class)]
class MessagesOptionsTest extends TestCase
{
    public function testTypeIsRespectedInsteadOfAlwaysGeneral(): void
    {
        $options = new MessagesOptions();
        $options->setType(MessagesEnumerator::COMBAT);

        // legacy always returned GENERAL here (is_object() on an int)
        $this->assertSame(MessagesEnumerator::COMBAT, $options->getType());
    }

    public function testTypeDefaultsToGeneralWhenNeverSet(): void
    {
        $this->assertSame(MessagesEnumerator::GENERAL, (new MessagesOptions())->getType());
    }

    public function testTimeDefaultsToNowWhenZero(): void
    {
        $options = new MessagesOptions();
        $options->setTime(0);

        $this->assertEqualsWithDelta(time(), $options->getTime(), 2);
    }

    /**
     * Regression: plain text was SQL-escaped although it is stored through a
     * bound parameter, so players saw a\'b and a literal \n.
     */
    public function testSimpleTextIsStoredAsIs(): void
    {
        $options = new MessagesOptions();
        $options->setMessageText("a'b\nc");

        $this->assertSame("a'b\nc", $options->getMessageText());
    }

    public function testHtmlTextIsKept(): void
    {
        $options = new MessagesOptions();
        $options->setMessageFormat(MessagesFormat::HTML);
        $options->setMessageText('<b>hi</b>');

        $this->assertSame('<b>hi</b>', $options->getMessageText());
    }
}
