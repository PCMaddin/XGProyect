<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\SearchController;
use App\Services\FormatService;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(SearchController::class)]
class SearchControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function searchTypeProvider(): iterable
    {
        yield 'player name is kept' => ['playerName', 'playerName'];
        yield 'alliance tag is kept' => ['allianceTag', 'allianceTag'];
        yield 'planet names is kept' => ['planetNames', 'planetNames'];
        yield 'unknown falls back to player name' => ['somethingElse', 'playerName'];
        yield 'empty falls back to player name' => ['', 'playerName'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('searchTypeProvider')]
    public function testNormalizeSearchTypeOnlyAllowsKnownTypes(string $input, string $expected): void
    {
        $controller = new SearchController(new FormatService());

        $method = new ReflectionMethod(SearchController::class, 'normalizeSearchType');

        $this->assertSame($expected, $method->invoke($controller, $input));
    }
}
