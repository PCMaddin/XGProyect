<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\CoversNothing;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * The game, the admin panel, the installer and the e-mails load nothing from
 * other servers: logos, fonts, stylesheets, scripts and images all come from
 * public/. Before, the logos came from xgproyect.org and the admin font from
 * Google Fonts, so every page view told those servers who played where.
 * Links a user clicks (<a href="https://...">) are fine.
 */
#[CoversNothing]
class NoExternalResourcesTest extends TestCase
{
    // an absolute or protocol-relative URL, after an optional quote
    private const EXTERNAL = '\s*["\']?\s*(?:https?:)?\/\/';

    private const VIEW_PATTERNS = [
        'link' => '/<link\b[^>]*\bhref\s*=' . self::EXTERNAL . '/i',
        'src' => '/\b(?:src|srcset|poster|background)\s*=' . self::EXTERNAL . '/i',
        'url()' => '/\burl\(' . self::EXTERNAL . '/i',
        '@import' => '/@import\s+(?:url\()?' . self::EXTERNAL . '/i',
    ];

    private const CSS_PATTERNS = [
        'url()' => self::VIEW_PATTERNS['url()'],
        '@import' => self::VIEW_PATTERNS['@import'],
    ];

    public function testNoViewLoadsFromAnotherServer(): void
    {
        $this->assertSame([], $this->findExternal('resources/views', '.blade.php', self::VIEW_PATTERNS));
    }

    public function testNoStylesheetLoadsFromAnotherServer(): void
    {
        $this->assertSame([], $this->findExternal('public', '.css', self::CSS_PATTERNS));
    }

    public function testTheCheckFindsExternalResources(): void
    {
        $found = [
            '<link href="https://fonts.googleapis.com/css?family=Nunito" rel="stylesheet">',
            '<img src="https://xgproyect.org/logo.png">',
            "<script src='//cdn.example.org/lib.js'></script>",
            'body { background: url(https://example.org/bg.jpg); }',
            '@import url("https://example.org/more.css");',
        ];
        $kept = [
            '<a href="https://xgproyect.org/" target="_blank">XG Proyect</a>',
            '<img src="{{ asset(\'assets/images/xgp-logo-white.png\') }}">',
            "background-image: url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg'%3e\");",
            '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
        ];

        foreach ($found as $source) {
            $this->assertNotSame([], $this->kindsIn($source, self::VIEW_PATTERNS), $source);
        }

        foreach ($kept as $source) {
            $this->assertSame([], $this->kindsIn($source, self::VIEW_PATTERNS), $source);
        }
    }

    public function testEveryAssetOfAViewExists(): void
    {
        $missing = [];

        foreach ($this->files('resources/views', '.blade.php') as $file => $source) {
            preg_match_all('/\basset\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $source, $assets);

            foreach ($assets[1] as $asset) {
                if (!is_file(public_path($asset))) {
                    $missing[] = $file . ': ' . $asset;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * @param array<string, string> $patterns
     *
     * @return list<string>
     */
    private function findExternal(string $directory, string $suffix, array $patterns): array
    {
        $found = [];

        foreach ($this->files($directory, $suffix) as $file => $source) {
            foreach ($this->kindsIn($source, $patterns) as $kind) {
                $found[] = $file . ': ' . $kind;
            }
        }

        return $found;
    }

    /**
     * @param array<string, string> $patterns
     *
     * @return list<string>
     */
    private function kindsIn(string $source, array $patterns): array
    {
        return array_keys(array_filter($patterns, fn (string $pattern): bool => preg_match($pattern, $source) === 1));
    }

    /**
     * @return array<string, string> file relative to the project => contents
     */
    private function files(string $directory, string $suffix): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
