<?php

declare(strict_types=1);

namespace Tests\Unit\App\Support;

use App\Http\Controllers\Admin\LanguagesController;
use App\Services\Instances\InstanceRegistry;
use App\Support\Html;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\DatabaseTestCase;

/**
 * Names stay text in the legacy HTML: escaped for tooltips (a script string
 * in an attribute) and for confirmations; names with markup are refused or
 * cleaned; the shared language files are not edited from one universe.
 */
#[CoversClass(Html::class)]
#[CoversClass(LanguagesController::class)]
class HtmlTest extends DatabaseTestCase
{
    public function testTextForATooltipIsEscapedForItsHtmlAndItsScriptString(): void
    {
        $text = Html::scriptText("O'Neil <b>\\x3c\n");

        // the browser decodes the attribute once: the script must see no quote, no tag, no escape
        $decoded = html_entity_decode(e($text), ENT_QUOTES | ENT_HTML5);
        $this->assertSame('O&#039;Neil &lt;b&gt;\\\\x3c ', $decoded);
    }

    public function testTextForAConfirmationEndsNeitherTheAttributeNorTheString(): void
    {
        $attribute = Html::attributeScript('a"b\'c\\');

        $this->assertStringNotContainsString('"', $attribute);
        $this->assertSame('a\\"b\\\'c\\\\', html_entity_decode($attribute, ENT_QUOTES | ENT_HTML5));
    }

    public function testNamesLoseTheCharactersThatEndHtmlText(): void
    {
        $this->assertSame('Badbname', Html::cleanName(' Bad"<b>name\'&\\` '));
        $this->assertSame(0, preg_match(Html::UNSAFE_IN_NAMES, 'Bot_ABC-12 x'));
        $this->assertSame(1, preg_match(Html::UNSAFE_IN_NAMES, "tab\tname"));
    }

    public function testTheMigrationCleansNamesFromBefore(): void
    {
        DB::table('users')->insert(['id' => 5, 'name' => 'Evil<script>', 'email' => 'e@example.com', 'home_planet_id' => 5, 'current_planet' => 5]);
        DB::table('users')->insert(['id' => 6, 'name' => 'Fine_Name', 'email' => 'f@example.com', 'home_planet_id' => 6, 'current_planet' => 6]);
        DB::table('planets')->insert(['planet_id' => 5, 'planet_user_id' => 5, 'planet_name' => '"><img src=x>']);
        DB::table('alliance')->insert(['alliance_id' => 3, 'alliance_name' => "Bad'Guys", 'alliance_tag' => '<T>', 'alliance_owner' => 5]);

        $migration = require database_path('migrations/400/2026_10_18_000041_clean_unsafe_names.php');
        $migration->up();

        $this->assertSame(['Evilscript', 'Fine_Name'], DB::table('users')->orderBy('id')->pluck('name')->all());
        $planetName = DB::table('planets')->where('planet_id', 5)->value('planet_name');
        $this->assertIsString($planetName);
        $this->assertSame('imgsrc=x', str_replace(' ', '', $planetName));
        $this->assertSame(['BadGuys', 'T'], array_values((array) DB::table('alliance')->where('alliance_id', 3)->first(['alliance_name', 'alliance_tag'])));
    }

    public function testTheLanguageFilesAreNotEditedFromOneOfSeveralUniverses(): void
    {
        $registry = $this->createStub(InstanceRegistry::class);
        $registry->method('enabled')->willReturn(true);
        $this->app->instance(InstanceRegistry::class, $registry);

        try {
            app(LanguagesController::class)->index(Request::create('/admin/languages'));
            $this->fail('the editor opened');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
