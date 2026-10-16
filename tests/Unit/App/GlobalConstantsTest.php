<?php

declare(strict_types=1);

namespace Tests\Unit\App;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Every global constant the code reads must be defined once the application
 * is booted (config/legacy/constants.php) or by the file that uses it.
 *
 * Regression: LIB_PATH, DPATH, IMG_PATH, XGP_ROOT and SYSTEM_ROOT were only
 * defined by the legacy bootstrap, which stopped running when the last game
 * page became a native controller. Every battle and the galaxy view crashed
 * with "Undefined constant".
 */
class GlobalConstantsTest extends TestCase
{
    private const SKIP_BEFORE = [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_CONST, T_FUNCTION, T_NEW, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_CASE, T_USE, T_NAMESPACE, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_GOTO, T_AS];

    /**
     * @return array<string, list<string>> constant name => files using it
     */
    private static function usedConstants(): array
    {
        $used = [];
        $root = dirname(__DIR__, 3);

        foreach (['app', 'legacy'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));

            foreach ($files as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                $code = file_get_contents($path);
                self::assertIsString($code);

                foreach (self::constantsIn($code) as $constant) {
                    $used[$constant][] = substr($path, strlen($root) + 1);
                }
            }
        }

        return $used;
    }

    /**
     * @return list<string>
     */
    private static function constantsIn(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn (array | string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $constants = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || preg_match('/^[A-Z][A-Z0-9_]*$/', $token[1]) !== 1) {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;

            if (is_array($previous) && in_array($previous[0], self::SKIP_BEFORE, true)) {
                continue;
            }

            if ($next === '(' || (is_array($next) && $next[0] === T_DOUBLE_COLON)) {
                continue;
            }

            $constants[] = $token[1];
        }

        return $constants;
    }

    public function testEveryUsedConstantIsDefined(): void
    {
        // the battle engine defines its own constants when a battle starts
        require_once dirname(__DIR__, 3) . '/legacy/app/Libraries/BattleEngine/Utils/Includer.php';

        $undefined = [];

        foreach (self::usedConstants() as $constant => $files) {
            if (!defined($constant) && !in_array($constant, ['SHIP_DEBRIS_FACTOR', 'DEFENSE_DEBRIS_FACTOR'], true)) {
                $undefined[] = $constant . ' (' . implode(', ', array_unique($files)) . ')';
            }
        }

        $this->assertSame([], $undefined);
    }
}
