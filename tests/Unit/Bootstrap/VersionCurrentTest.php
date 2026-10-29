<?php

declare(strict_types=1);

namespace Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Right after xgp:versions:use PHP may still resolve the web root
 * <versions>/current/public to the old folder (realpath cache).
 * public/index.php reads the link itself fresh and hands the request to
 * the version it names: no php-fpm reload. Never for a request the
 * universe router sent to an older version on purpose, never twice, and
 * nothing at all on a server without the link (Docker).
 */
#[CoversNothing]
class VersionCurrentTest extends TestCase
{
    private string $root;

    /** @var \Closure(string, array<string, mixed>): ?string */
    private \Closure $follow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/xgp-current-' . getmypid() . '/versions';
        self::deleteDirectory(dirname($this->root));

        foreach (['4.0.0', '4.1.0'] as $version) {
            mkdir($this->root . '/' . $version . '/public', 0o755, true);
            mkdir($this->root . '/' . $version . '/bootstrap', 0o755, true);
            file_put_contents($this->root . '/' . $version . '/public/index.php', '<?php echo "served by ' . $version . '";');
        }

        symlink($this->root . '/4.1.0', $this->root . '/current');

        $follow = require dirname(__DIR__, 3) . '/bootstrap/version-current.php';
        $this->assertInstanceOf(\Closure::class, $follow);
        $this->follow = $follow;
    }

    protected function tearDown(): void
    {
        self::deleteDirectory(dirname($this->root));

        parent::tearDown();
    }

    public function testARequestThatLandsInTheOldFolderGoesToTheVersionTheLinkNames(): void
    {
        $this->assertSame($this->root . '/4.1.0/public/index.php', ($this->follow)($this->root . '/4.0.0/public', []));
        // the version the link names serves itself
        $this->assertNull(($this->follow)($this->root . '/4.1.0/public', []));
    }

    public function testARelativeLinkNamesAFolderBesideIt(): void
    {
        unlink($this->root . '/current');
        symlink('4.1.0', $this->root . '/current');

        $this->assertSame($this->root . '/4.1.0/public/index.php', ($this->follow)($this->root . '/4.0.0/public', []));
    }

    public function testNotForAUniverseSentToAnOlderVersionAndNeverTwice(): void
    {
        $this->assertNull(($this->follow)($this->root . '/4.0.0/public', ['XGP_VERSION_DISPATCHED' => $this->root . '/4.0.0']));
        $this->assertNull(($this->follow)($this->root . '/4.0.0/public', ['XGP_CURRENT_FOLLOWED' => $this->root . '/4.1.0']));
    }

    public function testWithoutTheLinkOrWithoutAGameBehindItNothingChanges(): void
    {
        // a Docker server: /srv/app/public, no link beside it
        $this->assertNull(($this->follow)(dirname($this->root) . '/app/public', []));

        unlink($this->root . '/current');
        mkdir($this->root . '/empty');
        symlink($this->root . '/empty', $this->root . '/current');
        $this->assertNull(($this->follow)($this->root . '/4.0.0/public', []));
    }

    public function testTheRealFrontControllerHandsTheRequestOver(): void
    {
        // the old version's front controller and bootstrap files, as they are
        $old = $this->root . '/4.0.0';
        copy(dirname(__DIR__, 3) . '/public/index.php', $old . '/public/index.php');
        copy(dirname(__DIR__, 3) . '/bootstrap/version-current.php', $old . '/bootstrap/version-current.php');

        $process = new Process([PHP_BINARY, $old . '/public/index.php']);
        $process->run();

        $this->assertSame('served by 4.1.0', $process->getOutput(), $process->getErrorOutput());
    }

    private static function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }
}
