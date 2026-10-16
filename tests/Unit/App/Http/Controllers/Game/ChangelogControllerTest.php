<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\ChangelogController;
use App\Models\Changelog;
use App\Models\Options;
use Database\Seeders\ChangelogTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The changelog in the game's language, or in English when there is none in
 * it (German has no entries of its own).
 */
#[CoversClass(ChangelogController::class)]
class ChangelogControllerTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ChangelogTableSeeder::class, LanguagesTableSeeder::class]);
    }

    public function testShowsTheEntriesOfTheGameLanguage(): void
    {
        $this->assertSame($this->descriptions('es'), $this->shownFor('es'));
    }

    public function testFallsBackToEnglishForALanguageWithoutEntries(): void
    {
        $this->assertSame([], $this->descriptions('de'));
        $this->assertNotSame([], $this->descriptions('en'));
        $this->assertSame($this->descriptions('en'), $this->shownFor('de'));
    }

    /**
     * The changelog texts the page shows with the game in $language.
     *
     * @return list<string>
     */
    private function shownFor(string $language): array
    {
        foreach (['modules' => '1;1;1;1;1;1;1;1;1;1;1;1;1;1;1;1;1;1;1;1;1;1;1;1;1', 'lang' => $language, 'game_name' => 'XGP', 'date_format' => 'd.m.Y', 'date_time_zone' => 'UTC'] as $name => $value) {
            Options::insert(['name' => $name, 'value' => $value, 'type' => 'string']);
        }

        $changes = app(ChangelogController::class)()->getData()['changes'] ?? null;
        $this->assertInstanceOf(Collection::class, $changes);
        $shown = [];

        foreach ($changes as $change) {
            $this->assertIsArray($change);
            $this->assertIsString($change['description'] ?? null);

            // without the date in front
            $shown[] = (string) preg_replace('/^.*?<br>/s', '', $change['description']);
        }

        return $shown;
    }

    /**
     * The changelog texts stored in $language, newest first, as shown.
     *
     * @return list<string>
     */
    private function descriptions(string $language): array
    {
        return array_values(Changelog::query()
            ->join('languages', 'languages.id', '=', 'changelog.changelog_lang_id')
            ->where('languages.code', $language)
            ->orderByDesc('changelog_date')
            ->get()
            ->map(fn (Changelog $entry): string => nl2br($entry->changelog_description))
            ->all());
    }
}
