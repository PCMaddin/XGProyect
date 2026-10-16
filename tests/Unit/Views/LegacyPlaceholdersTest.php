<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * No Blade view may still hold a placeholder of the old template engine
 * ({name}): nothing replaces them any more. The federation page showed them
 * as text, the alliance texts form sent "{t}" (so every text was saved as the
 * external one) and the research countdown went to "cp={tech_home}".
 */
class LegacyPlaceholdersTest extends TestCase
{
    public function testNoViewHasOldTemplatePlaceholders(): void
    {
        $root = dirname(__DIR__, 3) . '/resources/views';
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // HTML comments and Blade echoes do not count
            $source = (string) preg_replace(['/<!--.*?-->/s', '/\{\{.*?\}\}|\{!!.*?!!\}/s'], '', (string) file_get_contents($file->getPathname()));

            if (preg_match_all('/\{\/?[a-z][a-z_]*\}/', $source, $matches) > 0) {
                $found[substr($file->getPathname(), strlen($root) + 1)] = array_values(array_unique($matches[0]));
            }
        }

        $this->assertSame([], $found);
    }
}
