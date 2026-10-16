<?php

declare(strict_types=1);

namespace Tests\Unit\App;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * English and Spanish must have the same keys, no empty texts and the same
 * placeholders. A missing key falls back to English, but an empty one shows
 * an empty message (the Spanish expedition reports were mostly empty), and
 * a placeholder too many makes sprintf() fail.
 */
class LanguageFilesTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function files(): array
    {
        $root = self::langPath('en');
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $files[$relative] = [$relative];
            }
        }

        ksort($files);

        return $files;
    }

    #[DataProvider('files')]
    public function testBothLanguagesHaveTheSameNonEmptyTexts(string $file): void
    {
        $this->assertFileExists(self::langPath('es') . '/' . $file);

        $english = self::texts('en', $file);
        $spanish = self::texts('es', $file);

        $this->assertSame([], array_keys(array_diff_key($english, $spanish)), 'missing in es');
        $this->assertSame([], array_keys(array_diff_key($spanish, $english)), 'missing in en');
        $this->assertSame([], array_keys(array_filter($english + $spanish, fn (string $text): bool => $text === '')), 'empty');

        foreach ($english as $key => $text) {
            $this->assertSame(self::placeholders($text), self::placeholders($spanish[$key] ?? ''), 'placeholders of ' . $key);
        }
    }

    private static function langPath(string $language): string
    {
        return dirname(__DIR__, 3) . '/lang/' . $language;
    }

    /**
     * @return array<string, string>
     */
    private static function texts(string $language, string $file): array
    {
        $texts = require self::langPath($language) . '/' . $file;

        return is_array($texts) ? self::flatten($texts) : [];
    }

    /**
     * @param array<mixed> $texts
     *
     * @return array<string, string>
     */
    private static function flatten(array $texts, string $prefix = ''): array
    {
        $flat = [];

        foreach ($texts as $key => $text) {
            $name = $prefix . $key;

            if (is_array($text)) {
                $flat += self::flatten($text, $name . '.');
            } elseif (is_scalar($text)) {
                $flat[$name] = (string) $text;
            }
        }

        return $flat;
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%(?:\d+\$)?[sdfu]|:[a-z_]+\b/i', $text, $matches);
        $placeholders = $matches[0];
        sort($placeholders);

        return $placeholders;
    }
}
