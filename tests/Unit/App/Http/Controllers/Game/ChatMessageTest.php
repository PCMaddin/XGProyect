<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\ChatController;
use App\Services\FormatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\DatabaseTestCase;

/**
 * Regression (stored XSS): subject and text of a private message are shown
 * as HTML ({!! !!}), but the player's input was stored without HTML
 * escaping; and the SQL-escaping that was applied instead turned it's into
 * it\'s and line breaks into a literal \n.
 */
#[CoversClass(ChatController::class)]
class ChatMessageTest extends DatabaseTestCase
{
    public function testThePlayersInputIsHtmlEscapedAndKeptReadable(): void
    {
        $controller = new ChatController(new FormatService());
        (new ReflectionProperty(ChatController::class, 'user'))
            ->setValue($controller, ['id' => 1, 'name' => 'Sender', 'galaxy' => 1, 'system' => 2, 'planet' => 3]);

        (new ReflectionMethod(ChatController::class, 'handleSubmission'))
            ->invoke($controller, new Request(['subject' => '<b>Hi</b>', 'text' => "<script>x()</script>\nit's me"]), 2);

        $message = DB::table('messages')->where('message_receiver', 2)->first(['message_subject', 'message_text']);
        $this->assertNotNull($message);
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', $message->message_subject);
        $this->assertSame("&lt;script&gt;x()&lt;/script&gt;\nit&#039;s me", $message->message_text);
    }
}
