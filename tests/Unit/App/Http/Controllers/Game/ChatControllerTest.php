<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\ChatController;
use App\Services\FormatService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(ChatController::class)]
class ChatControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function fieldProvider(): iterable
    {
        yield 'both empty reports subject first' => ['', '', 'subject'];
        yield 'missing subject' => ['', 'body', 'subject'];
        yield 'missing text' => ['subject', '', 'text'];
        yield 'both present is valid' => ['subject', 'body', null];
    }

    #[DataProvider('fieldProvider')]
    public function testMissingFieldDetectsEmptyRequiredFields(string $subject, string $text, ?string $expected): void
    {
        $controller = new ChatController(new FormatService());

        $method = new ReflectionMethod(ChatController::class, 'missingField');

        $this->assertSame($expected, $method->invoke($controller, $subject, $text));
    }
}
