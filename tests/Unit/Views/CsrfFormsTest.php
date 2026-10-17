<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use App\Http\Kernel;
use App\Http\Middleware\VerifyCsrfToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * The game checks CSRF tokens like the rest of the application, and every
 * form that posts sends one. Before, the check was switched off for the game:
 * another site could make a logged-in player send fleets, write messages,
 * change settings or mark the account for deletion.
 */
class CsrfFormsTest extends TestCase
{
    public function testEveryRouteGroupChecksTheToken(): void
    {
        $groups = $this->app->make(Kernel::class)->getMiddlewareGroups();

        foreach (['web', 'install', 'game'] as $group) {
            $this->assertContains(VerifyCsrfToken::class, $groups[$group], $group);
        }
    }

    public function testEveryPostingFormSendsTheToken(): void
    {
        $root = dirname(__DIR__, 3) . '/resources/views';
        $missing = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/<form\b[^>]*>(.*?)<\/form>/is', $source, $forms, PREG_SET_ORDER);

            foreach ($forms as [$form, $body]) {
                if (preg_match('/^<form\b[^>]*method\s*=\s*["\']?post/i', $form) === 1 && preg_match('/@csrf|csrf_field\(\)|csrf_token\(\)/', $body) !== 1) {
                    $missing[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $missing);
    }
}
