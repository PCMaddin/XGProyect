<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Exceptions\HaltWithView;
use App\Libraries\Template;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

#[CoversClass(Template::class)]
class TemplateTest extends DatabaseTestCase
{
    /**
     * Regression: a missing template rendered as null, which made the
     * jsReady(Template::render(...)) callers throw a TypeError.
     */
    public function testAMissingTemplateRendersAsAnEmptyString(): void
    {
        $this->assertSame('', Template::render('does.not.exist'));
        $this->assertSame('', Template::jsReady(Template::render('does.not.exist')));
    }

    public function testJsReadyJoinsTheTrimmedNonEmptyLines(): void
    {
        $this->assertSame('<div>a</div><b>c</b>', Template::jsReady("  <div>a</div>\r\n\n\t<b>c</b>  \r"));
    }

    public function testHaltWithViewEndsTheRequestWithTheView(): void
    {
        DB::table('options')->insert(['name' => 'game_name', 'value' => 'XGP', 'type' => '32']);
        $this->expectException(HaltWithView::class);

        Template::haltWithView('message.view', ['mes' => 'hello', 'middle1' => '', 'middle2' => '']);
    }
}
