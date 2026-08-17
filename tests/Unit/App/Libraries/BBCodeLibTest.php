<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\BBCodeLib;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(BBCodeLib::class)]
class BBCodeLibTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function tagProvider(): iterable
    {
        yield 'bold' => ['[b]hi[/b]', '<span style="font-weight: bold;">hi</span>'];
        yield 'strong maps to bold' => ['[strong]hi[/strong]', '<span style="font-weight: bold;">hi</span>'];
        yield 'italic' => ['[i]hi[/i]', '<span style="font-style: italic;">hi</span>'];
        yield 'underline' => ['[u]hi[/u]', '<span style="text-decoration: underline;">hi</span>'];
        yield 'strike' => ['[s]hi[/s]', '<span style="text-decoration: line-through;">hi</span>'];
        yield 'color' => ['[color=red]hi[/color]', '<span style="color:red">hi</span>'];
        yield 'size' => ['[size=12]hi[/size]', '<span style="font-size:12px">hi</span>'];
        yield 'newline becomes break' => ["a\nb", 'a<br>b'];
        yield 'carriage return removed' => ["a\rb", 'ab'];
        yield 'plain text untouched' => ['nothing here', 'nothing here'];
    }

    #[DataProvider('tagProvider')]
    public function testTagsRenderToHtml(string $input, string $expected): void
    {
        $this->assertSame($expected, app(BBCodeLib::class)->bbCode($input));
    }

    public function testListRendersItems(): void
    {
        $result = app(BBCodeLib::class)->bbCode('[list]one[*]two[/list]');

        $this->assertSame('<ul><li>one</li><li>two</li></ul>', $result);
    }

    public function testUrlWithDangerousSchemeIsNotLinked(): void
    {
        $result = app(BBCodeLib::class)->bbCode('[url=javascript:alert(1)]click[/url]');

        $this->assertStringNotContainsString('<a', $result);
    }

    public function testNullInputYieldsEmptyString(): void
    {
        $this->assertSame('', app(BBCodeLib::class)->bbCode(null));
    }
}
