<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Models\Languages;
use App\Models\Options;
use Database\Seeders\LanguagesTableSeeder;
use Illuminate\Database\Migrations\Migration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

/**
 * The update migrations for the languages: their rows, and a valid server
 * language instead of the "spanish" the installer stored.
 */
class LanguageMigrationsTest extends DatabaseTestCase
{
    /**
     * The migrations that add a language for existing installs.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function addedLanguages(): array
    {
        return [
            'de' => ['2026_10_16_000001_add_german_language.php', 'de', 'Deutsch'],
            'fr' => ['2026_10_16_000022_add_french_language.php', 'fr', 'Français'],
            'pt_BR' => ['2026_10_16_000023_add_portuguese_brazil_language.php', 'pt_BR', 'Português (Brasil)'],
            'nl' => ['2026_10_16_000024_add_dutch_language.php', 'nl', 'Nederlands'],
            'it' => ['2026_10_16_000025_add_italian_language.php', 'it', 'Italiano'],
            'pl' => ['2026_10_16_000026_add_polish_language.php', 'pl', 'Polski'],
        ];
    }

    #[DataProvider('addedLanguages')]
    public function testTheLanguageIsAddedOnce(string $file, string $code, string $name): void
    {
        $this->seed(LanguagesTableSeeder::class);

        self::migrate($file);
        $this->assertSame(1, Languages::where('code', $code)->count());

        Languages::where('code', $code)->delete();
        self::migrate($file);
        $this->assertSame(1, Languages::where('code', $code)->count());
        $this->assertSame($name, Languages::where('code', $code)->value('name'));
        $this->assertSame($code, Languages::orderByDesc('id')->value('code'));
    }

    public function testAnUnknownServerLanguageBecomesEnglish(): void
    {
        $this->assertSame('en', $this->serverLanguageAfterMigration('spanish'));
    }

    public function testAValidServerLanguageIsKept(): void
    {
        $this->assertSame('de', $this->serverLanguageAfterMigration('de'));
        $this->assertSame('es', $this->serverLanguageAfterMigration('es'));
        $this->assertSame('fr', $this->serverLanguageAfterMigration('fr'));
    }

    private function serverLanguageAfterMigration(string $language): string
    {
        Options::where('name', 'lang')->delete();
        Options::insert(['name' => 'lang', 'value' => $language, 'type' => 'string']);

        self::migrate('2026_10_16_000002_normalize_server_language.php');

        return Options::where('name', 'lang')->firstOrFail()->value;
    }

    private static function migrate(string $file): void
    {
        $migration = require dirname(__DIR__, 3) . '/database/migrations/400/' . $file;
        self::assertInstanceOf(Migration::class, $migration);

        if (!method_exists($migration, 'up')) {
            self::fail($file . ' has no up()');
        }

        $migration->up();
    }
}
