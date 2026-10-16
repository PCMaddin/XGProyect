<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Models\Languages;
use App\Models\Options;
use Database\Seeders\LanguagesTableSeeder;
use Illuminate\Database\Migrations\Migration;
use Tests\DatabaseTestCase;

/**
 * The update migrations for German: the languages row, and a valid server
 * language instead of the "spanish" the installer stored.
 */
class LanguageMigrationsTest extends DatabaseTestCase
{
    public function testGermanIsAddedOnce(): void
    {
        $this->seed(LanguagesTableSeeder::class);

        self::migrate('2026_10_16_000001_add_german_language.php');
        $this->assertSame(1, Languages::where('code', 'de')->count());

        Languages::where('code', 'de')->delete();
        self::migrate('2026_10_16_000001_add_german_language.php');
        $this->assertSame(1, Languages::where('code', 'de')->count());
        $this->assertSame(['es', 'en', 'fr', 'de'], Languages::orderBy('id')->pluck('code')->all());
    }

    public function testFrenchIsAddedOnce(): void
    {
        $this->seed(LanguagesTableSeeder::class);

        self::migrate('2026_10_16_000022_add_french_language.php');
        $this->assertSame(1, Languages::where('code', 'fr')->count());

        Languages::where('code', 'fr')->delete();
        self::migrate('2026_10_16_000022_add_french_language.php');
        $this->assertSame(['es', 'en', 'de', 'fr'], Languages::orderBy('id')->pluck('code')->all());
        $this->assertSame('Français', Languages::where('code', 'fr')->value('name'));
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
