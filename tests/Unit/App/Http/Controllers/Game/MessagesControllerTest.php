<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\MessagesController;
use App\Services\Game\Formulas\OfficerService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(MessagesController::class)]
class MessagesControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?int}>
     */
    public static function messageIdProvider(): iterable
    {
        yield 'valid marked id' => ['delmes42', 'delmes', 42];
        yield 'valid shown id' => ['showmes7', 'showmes', 7];
        yield 'zero id is rejected' => ['delmes0', 'delmes', null];
        yield 'wrong prefix is rejected' => ['showmes5', 'delmes', null];
        yield 'non-numeric suffix is rejected' => ['delmesX', 'delmes', null];
        yield 'sql injection suffix is rejected' => ['delmes1) OR 1=1--', 'delmes', null];
        yield 'plain field is rejected' => ['deletemessages', 'delmes', null];
    }

    #[DataProvider('messageIdProvider')]
    public function testParseMessageIdRejectsNonPositiveIntegerSuffixes(string $key, string $prefix, ?int $expected): void
    {
        $controller = new MessagesController(new OfficerService());

        $method = new ReflectionMethod(MessagesController::class, 'parseMessageId');

        $this->assertSame($expected, $method->invoke($controller, $key, $prefix));
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function noteColorProvider(): iterable
    {
        yield 'low priority is lime' => [0, 'lime'];
        yield 'medium priority is yellow' => [1, 'yellow'];
        yield 'high priority is red' => [2, 'red'];
    }

    #[DataProvider('noteColorProvider')]
    public function testNoteColorMapsPriority(int $priority, string $expected): void
    {
        $controller = new MessagesController(new OfficerService());

        $method = new ReflectionMethod(MessagesController::class, 'noteColor');

        $this->assertSame($expected, $method->invoke($controller, $priority));
    }
}
