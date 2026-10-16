<?php

declare(strict_types=1);

namespace Tests\Unit\App;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Counted texts go through trans_choice, so every language can use its own
 * plural forms (one form in Japanese and Chinese, three in Polish). The game
 * used to glue suffixes like "member" + "s" together, which no other
 * language could follow.
 */
class PluralTranslationsTest extends TestCase
{
    private const LANGUAGES = ['en', 'es', 'de', 'fr', 'pt_BR', 'nl', 'it', 'pl', 'ja', 'zh_CN'];

    private const KEYS = [
        'admin/bots.bo_created',
        'admin/errors.er_errors',
        'admin/errors.er_occurrences',
        'game/alliance.al_new_requests',
        'game/alliance.al_no_request_pending',
        'game/banned.bn_players_banned',
        'game/events.ev_ships_count',
        'game/galaxy.gl_alliance_members',
        'game/missile.mis_some_destroyed',
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function texts(): array
    {
        $texts = [];

        foreach (self::LANGUAGES as $language) {
            foreach (self::KEYS as $key) {
                $texts[$language . ' ' . $key] = [$language, $key];
            }
        }

        return $texts;
    }

    #[DataProvider('texts')]
    public function testEveryCountGetsOneForm(string $language, string $key): void
    {
        foreach ([1, 2, 3, 4, 5, 11, 12, 21, 22, 25, 101, 1000] as $count) {
            $text = trans_choice($key, $count, ['count' => $count], $language);

            $this->assertStringNotContainsString('|', $text, $key . ' ' . $count);
            $this->assertStringNotContainsString(':count', $text, $key . ' ' . $count);
            $this->assertStringContainsString((string) $count, $text, $key . ' ' . $count);
        }
    }

    public function testPolishUsesItsThreeForms(): void
    {
        $forms = [1 => 'statek', 2 => 'statki', 4 => 'statki', 5 => 'statków', 12 => 'statków', 22 => 'statki', 25 => 'statków'];

        foreach ($forms as $count => $form) {
            $this->assertSame($count . ' ' . $form, trans_choice('game/events.ev_ships_count', $count, ['count' => $count], 'pl'));
        }

        $this->assertSame('Brak błędów', trans_choice('admin/errors.er_errors', 0, ['count' => 0], 'pl'));
    }

    public function testEnglishAndGermanUseSingularAndPlural(): void
    {
        $this->assertSame('consisting of 1 member', trans_choice('game/galaxy.gl_alliance_members', 1, ['count' => 1], 'en'));
        $this->assertSame('consisting of 7 members', trans_choice('game/galaxy.gl_alliance_members', 7, ['count' => 7], 'en'));
        $this->assertSame('mit 1 Mitglied', trans_choice('game/galaxy.gl_alliance_members', 1, ['count' => 1], 'de'));
        $this->assertSame('mit 7 Mitgliedern', trans_choice('game/galaxy.gl_alliance_members', 7, ['count' => 7], 'de'));
        $this->assertSame('Keine offenen Bewerbungen', trans_choice('game/alliance.al_no_request_pending', 0, ['count' => 0], 'de'));
    }

    public function testEveryCountedKeyInTheCodeIsChecked(): void
    {
        $root = dirname(__DIR__, 3);
        $used = [];

        foreach (['app', 'resources/views'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    preg_match_all("/trans_choice\\('([^']+)'/", (string) file_get_contents($file->getPathname()), $matches);
                    $used = array_merge($used, $matches[1]);
                }
            }
        }

        $used = array_values(array_unique($used));
        sort($used);

        $this->assertSame(self::KEYS, $used);
    }
}
