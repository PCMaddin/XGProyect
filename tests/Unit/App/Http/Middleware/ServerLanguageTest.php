<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Middleware;

use App\Http\Middleware\ServerLanguage;
use App\Models\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\DatabaseTestCase;

/**
 * Pages outside the installer speak the server language, whatever language
 * the session still holds; a language without a directory means English.
 */
#[CoversClass(ServerLanguage::class)]
class ServerLanguageTest extends DatabaseTestCase
{
    public function testThePageSpeaksTheServerLanguage(): void
    {
        $this->assertSame('de', $this->localeWith('de'));
        $this->assertSame('es', $this->localeWith('es'));
    }

    public function testTheSessionLanguageDoesNotWin(): void
    {
        session(['locale' => 'es']);

        $this->assertSame('de', $this->localeWith('de'));
    }

    public function testAnUnknownLanguageIsEnglish(): void
    {
        $this->assertSame('en', $this->localeWith('spanish'));
    }

    private function localeWith(string $language): string
    {
        Options::where('name', 'lang')->delete();
        Options::insert(['name' => 'lang', 'value' => $language, 'type' => 'string']);
        App::setLocale('en');

        $locale = '';
        (new ServerLanguage())->handle(new Request(), function () use (&$locale): Response {
            $locale = App::getLocale();

            return new Response();
        });

        return $locale;
    }
}
