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

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function xssProvider(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'image breaks out' => ['[img]http://x/a.png" onerror="alert(1)[/img]'];
        yield 'relative image breaks out' => ['[img]a" onerror="alert(1)[/img]'];
        yield 'color breaks out' => ['[color=red" onmouseover="alert(1)]x[/color]'];
        yield 'size breaks out' => ['[size=1px;" onmouseover="alert(1)]x[/size]'];
        yield 'upper case javascript url' => ['[url=JavaScript:alert(1)]x[/url]'];
        yield 'url breaks out' => ['[url=http://x/" onmouseover="alert(1)]x[/url]'];
        yield 'nested tag in url' => ['[url=http://x/[b]a[/b]]x[/url]'];
        yield 'email breaks out' => ['[email=a@b.c" onmouseover="alert(1)]x[/email]'];
    }

    /**
     * Regression (stored XSS): alliance texts were not escaped, and tag
     * arguments could leave their attribute.
     */
    #[DataProvider('xssProvider')]
    public function testPlayerInputCannotInjectMarkup(string $input): void
    {
        $result = app(BBCodeLib::class)->bbCode($input);

        $this->assertStringNotContainsString('<script', $result);
        $this->assertDoesNotMatchRegularExpression('/<[^>]*\son[a-z]+\s*=\s*"/i', $result);
        $this->assertDoesNotMatchRegularExpression('/href="javascript/i', $result);
    }

    public function testValidArgumentsStillRender(): void
    {
        $bbCode = app(BBCodeLib::class);

        $this->assertSame('<a href="https://xgp.test/a?b=1&amp;c=2" title="Site">Site</a>', $bbCode->bbCode('[url=https://xgp.test/a?b=1&c=2]Site[/url]'));
        $this->assertSame('<img src="assets/images/logo.png" alt="assets/images/logo.png" title="assets/images/logo.png" />', $bbCode->bbCode('[img]logo.png[/img]'));
        $this->assertSame('<a href="mailto:a@b.de" title="a@b.de">Mail</a>', $bbCode->bbCode('[email=a@b.de]Mail[/email]'));
        $this->assertSame('a &lt; b &amp; &quot;c&quot;', $bbCode->bbCode('a < b & "c"'));
    }

    public function testNullInputYieldsEmptyString(): void
    {
        $this->assertSame('', app(BBCodeLib::class)->bbCode(null));
    }
}
