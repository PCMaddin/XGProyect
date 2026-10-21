<?php

declare(strict_types=1);

namespace App\Services\Instances;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A new version of the code from git, into a folder of its own under the
 * versions path (a server without Docker): cloned at a tag or branch,
 * dependencies installed, caches built, settings linked. Nothing serves it
 * until `use` points the folder `current` at it; the web root is
 * <versions path>/current/public, so the switch is one symlink, and the
 * older versions stay beside it for the universes still playing on them.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class VersionFetcher
{
    public const CURRENT = 'current';

    /** a clone with dependencies: minutes, not seconds */
    private const TIMEOUT = 1800;

    public function __construct(private Versions $versions, private ?string $source = null)
    {
    }

    /**
     * Clones the given tag or branch (the newest tag when none) of the
     * repository (the origin of this checkout when none) into
     * <versions path>/<version>. Returns what was done, one line each.
     *
     * @return list<string>
     */
    public function fetch(?string $ref, ?string $repository): array
    {
        $repository = $repository ?? $this->origin();
        $ref = $ref ?? $this->newestTag($repository);
        $root = $this->versions->root();
        $temporary = $root . '/.fetching-' . getmypid();

        if (!is_dir($root) && !mkdir($root, 0o750, true) && !is_dir($root)) {
            throw new RuntimeException('Cannot create ' . $root);
        }

        $done = ['Cloning ' . $ref . ' of ' . $repository . '.'];

        try {
            $this->run(['git', 'clone', '--quiet', '--depth', '1', '--branch', $ref, $repository, $temporary], $root);
            $version = $this->versionOf($temporary);
            $target = $root . '/' . $version;

            if (is_dir($target)) {
                throw new RuntimeException('The version ' . $version . ' is on the server already (' . $target . '); raise config/version.php before publishing a new release.');
            }

            $done[] = 'Version ' . $version . ' (' . trim($this->run(['git', 'rev-parse', '--short=12', 'HEAD'], $temporary)) . ').';
            $done[] = $this->install($temporary);
            $this->settle($temporary, trim($this->run(['git', 'rev-parse', 'HEAD'], $temporary)));
            $done[] = $this->cache($temporary);

            if (!rename($temporary, $target)) {
                throw new RuntimeException('Cannot move the clone to ' . $target);
            }
        } catch (RuntimeException $e) {
            $this->delete($temporary);

            throw $e;
        }

        $done[] = 'Version ' . $version . ' is in ' . $target . '. Serve it with: php artisan xgp:versions:use ' . $version;

        return $done;
    }

    /**
     * Points <versions path>/current at the version: the web root
     * current/public and the scheduler's current/artisan follow at once.
     * The version's own code then writes the router's map.
     */
    public function use(string $version): string
    {
        $directory = $this->versions->directory($version);

        if ($directory === null) {
            throw new RuntimeException('The version ' . $version . ' is not on the server (xgp:versions).');
        }

        if (!is_file($directory . '/vendor/autoload.php')) {
            throw new RuntimeException('The version ' . $version . ' has no dependencies installed (composer install in ' . $directory . ').');
        }

        $link = $this->versions->root() . '/' . self::CURRENT;
        $temporary = $link . '.' . getmypid();

        if (!symlink($directory, $temporary) || !rename($temporary, $link)) {
            if (is_link($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException('Cannot point ' . $link . ' at ' . $directory);
        }

        return $directory;
    }

    /**
     * The repository this checkout came from.
     */
    private function origin(): string
    {
        try {
            return trim($this->run(['git', 'remote', 'get-url', 'origin'], $this->source ?? base_path()));
        } catch (RuntimeException) {
            throw new RuntimeException('This checkout has no git origin; name the repository with --repo.');
        }
    }

    private function newestTag(string $repository): string
    {
        $tags = [];

        foreach (explode("\n", $this->run(['git', 'ls-remote', '--tags', '--refs', $repository], $this->versions->root())) as $line) {
            $tag = substr(strrchr(trim($line), '/') ?: '', 1);

            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        if ($tags === []) {
            throw new RuntimeException('The repository has no tags; name a tag or branch.');
        }

        usort($tags, static fn (string $one, string $other): int => version_compare(ltrim($one, 'v'), ltrim($other, 'v')));

        return end($tags);
    }

    /**
     * The version of a checkout, from its config/version.php, checked as
     * a directory name.
     */
    private function versionOf(string $checkout): string
    {
        $file = $checkout . '/config/version.php';

        if (!is_file($file)) {
            throw new RuntimeException('No config/version.php in the clone: not the game.');
        }

        $version = trim($this->run([PHP_BINARY, '-r', 'echo (require $argv[1])["files"] ?? "";', $file], $checkout));

        if (preg_match(Versions::PATTERN, $version) !== 1) {
            throw new RuntimeException('The version in config/version.php is no directory name: "' . $version . '".');
        }

        return $version;
    }

    private function install(string $checkout): string
    {
        if (!is_file($checkout . '/composer.json')) {
            return 'No composer.json: nothing to install.';
        }

        $composer = (new ExecutableFinder())->find('composer');

        if ($composer === null) {
            throw new RuntimeException('composer is not installed; the dependencies cannot be installed.');
        }

        $this->run([$composer, 'install', '--no-dev', '--no-interaction', '--no-progress', '--optimize-autoloader', '--prefer-dist'], $checkout);

        return 'Dependencies installed (composer install --no-dev).';
    }

    /**
     * What a version needs besides the code: the storage folders, its
     * release mark and the settings, shared with the running version.
     */
    private function settle(string $checkout, string $commit): void
    {
        foreach (['storage/app/backups', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $directory) {
            if (!is_dir($checkout . '/' . $directory)) {
                mkdir($checkout . '/' . $directory, 0o770, true);
            }
        }

        file_put_contents($checkout . '/.release', $commit . "\n");

        $settings = ($this->source ?? base_path()) . '/.env';

        if (is_file($settings) && !file_exists($checkout . '/.env')) {
            symlink(realpath($settings) ?: $settings, $checkout . '/.env');
        }
    }

    private function cache(string $checkout): string
    {
        if (!is_file($checkout . '/vendor/autoload.php')) {
            return 'No vendor/: caches not built.';
        }

        foreach (['config:cache', 'route:cache', 'view:cache'] as $command) {
            $this->run([PHP_BINARY, $checkout . '/artisan', $command, '--no-interaction'], $checkout);
        }

        return 'Configuration, routes and views cached.';
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, string $directory): string
    {
        $process = new Process($command, $directory, null, null, self::TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(implode(' ', array_slice($command, 0, 2)) . ' failed: ' . trim($process->getErrorOutput() . "\n" . $process->getOutput()));
        }

        return $process->getOutput();
    }

    private function delete(string $directory): void
    {
        if (is_dir($directory)) {
            (new Process(['rm', '-rf', $directory]))->run();
        }
    }
}
