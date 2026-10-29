<?php

declare(strict_types=1);

namespace Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Before the framework starts, public/index.php asks bootstrap/version-router.php
 * whether the request belongs to a universe on another version of the code:
 * then that version's public/index.php serves it. The universe comes from
 * the query, the lobby cookie or Stripe's webhook payload; a version is a
 * plain directory name under the versions path; without a map, or for the
 * running version, the request stays here.
 */
#[CoversNothing]
class VersionRouterTest extends TestCase
{
    private string $folder;

    private string $map;

    /** @var \Closure(array<string, mixed>, array<string, mixed>, array<string, mixed>, string): ?string */
    private \Closure $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir() . '/xgp-router-' . getmypid();
        self::deleteDirectory($this->folder);
        mkdir($this->folder . '/3.9.0/public', 0o755, true);
        file_put_contents($this->folder . '/3.9.0/public/index.php', '<?php');
        $this->map = $this->folder . '/versions.json';
        file_put_contents($this->map, (string) json_encode(['current' => '4.0.0', 'root' => $this->folder, 'universes' => ['old' => '3.9.0', 'gone' => '3.8.0', 'bad' => '../etc']]));

        $router = require dirname(__DIR__, 3) . '/bootstrap/version-router.php';
        $this->assertInstanceOf(\Closure::class, $router);
        $this->router = $router;
    }

    protected function tearDown(): void
    {
        self::deleteDirectory($this->folder);

        parent::tearDown();
    }

    /**
     * No framework here: the router runs before it, so does this test.
     */
    private static function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }

    public function testTheLobbyCookieNamesTheUniverseAndItsVersionServes(): void
    {
        $this->assertSame($this->folder . '/3.9.0/public/index.php', $this->route(['REQUEST_URI' => '/game.php?page=overview'], [], ['xgp_universe' => 'old']));
    }

    public function testAMailLinkNamesTheUniverseInTheQueryWhichWins(): void
    {
        $this->assertSame($this->folder . '/3.9.0/public/index.php', $this->route(['REQUEST_URI' => '/account/verify/1/x?universe=old'], ['universe' => 'old'], ['xgp_universe' => 'new']));
    }

    public function testAUniverseOnTheRunningVersionStaysHere(): void
    {
        $this->assertNull($this->route(['REQUEST_URI' => '/'], [], ['xgp_universe' => 'new']));
        $this->assertNull($this->route(['REQUEST_URI' => '/'], [], []));
    }

    public function testAVersionThatIsNotOnTheServerOrNoDirectoryNameStaysHere(): void
    {
        // served here, where the middleware answers 503 instead of a wrong version
        $this->assertNull($this->route(['REQUEST_URI' => '/'], [], ['xgp_universe' => 'gone']));
        $this->assertNull($this->route(['REQUEST_URI' => '/'], [], ['xgp_universe' => 'bad']));
    }

    public function testTheLobbyTheOperatorsAndTheLegalPagesStayOnTheRunningVersion(): void
    {
        // before, the lobby cookie took the operators' area to an old version's code
        foreach (['/universes', '/universes/old', '/operator', '/operator/versions', '/legal/imprint?lang=de', '/install/', '/up'] as $uri) {
            $this->assertNull($this->route(['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'POST'], [], ['xgp_universe' => 'old']), $uri);
        }

        // a game page whose name only starts alike still goes
        $this->assertSame($this->folder . '/3.9.0/public/index.php', $this->route(['REQUEST_URI' => '/universes-guide'], [], ['xgp_universe' => 'old']));
    }

    public function testAChangedMapCountsAtOnceUnderTheOpcacheOfTheDockerStack(): void
    {
        // the Docker stack never checks files for changes; a map included as
        // PHP stayed in the opcache, and a universe moved to the newest
        // version answered 503 until the next deploy
        touch($this->map, time() - 60);
        $script = $this->folder . '/twice.php';
        file_put_contents($script, '<?php
            $route = require $argv[1];
            $ask = static fn (): string => (string) ($route(["REQUEST_URI" => "/"], [], ["xgp_universe" => "old"], $argv[2]) ?? "here");
            echo $ask(), "|";
            file_put_contents($argv[2] . ".new", json_encode(["current" => "4.0.0", "root" => $argv[3], "universes" => []]));
            rename($argv[2] . ".new", $argv[2]);
            echo $ask();');

        $process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0', $script, dirname(__DIR__, 3) . '/bootstrap/version-router.php', $this->map, $this->folder]);
        $process->run();

        $this->assertSame($this->folder . '/3.9.0/public/index.php|here', $process->getOutput(), $process->getErrorOutput());
    }

    public function testADispatchedRequestIsNeverDispatchedAgain(): void
    {
        $this->assertNull($this->route(['REQUEST_URI' => '/', 'XGP_VERSION_DISPATCHED' => '/srv/versions/3.9.0'], [], ['xgp_universe' => 'old']));
    }

    public function testWithoutAMapEverythingStaysHere(): void
    {
        unlink($this->map);

        $this->assertNull($this->route(['REQUEST_URI' => '/'], [], ['xgp_universe' => 'old']));
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $get
     * @param array<string, mixed> $cookie
     */
    private function route(array $server, array $get, array $cookie): ?string
    {
        return ($this->router)($server, $get, $cookie, $this->map);
    }
}
