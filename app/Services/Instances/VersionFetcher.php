<?php

declare(strict_types=1);

namespace App\Services\Instances;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A new version of the code from git, into a folder of its own under the
 * versions path (a server without Docker): fetched at a tag, a branch or
 * a commit, dependencies installed, caches built, settings linked. Nothing serves it
 * until `use` points the folder `current` at it; the web root is
 * <versions path>/current/public, so the switch is one symlink, and the
 * older versions stay beside it for the universes still playing on them.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class VersionFetcher
{
    public const CURRENT = 'current';

    /** a clone with dependencies: minutes, not seconds */
    private const TIMEOUT = 1800;

    public function __construct(private Versions $versions, private ?string $source = null, private ?string $logFile = null)
    {
    }

    /**
     * Starts the fetch as a process of its own (a clone with dependencies
     * takes minutes, a page cannot wait): xgp:versions:fetch of this
     * checkout, its output appended to the log. Returns the log file.
     */
    public function fetchInBackground(?string $ref, ?string $repository): string
    {
        if ($this->inProgress()) {
            throw new RuntimeException('A version is being fetched already.');
        }

        $log = $this->logFile();
        $command = [PHP_BINARY, ($this->source ?? base_path()) . '/artisan', 'xgp:versions:fetch', '--no-interaction'];

        if ($ref !== null && $ref !== '') {
            $command[] = $ref;
        }

        if ($repository !== null && $repository !== '') {
            $command[] = '--repo=' . $repository;
        }

        file_put_contents($log, "\n== " . gmdate('Y-m-d H:i:s') . ' UTC: ' . implode(' ', array_slice($command, 2)) . "\n", FILE_APPEND);
        $shell = 'nohup ' . implode(' ', array_map('escapeshellarg', $command)) . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
        $process = Process::fromShellCommandline($shell, $this->source ?? base_path(), ['XGP_VERSIONS_PATH' => $this->versions->root()], null, 10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('The fetch could not be started: ' . trim($process->getErrorOutput()));
        }

        return $log;
    }

    /**
     * The repositories the operators' area may fetch from: the origin of
     * this checkout and those in XGP_VERSIONS_REPOSITORIES. A fetch runs
     * the fetched code (composer scripts, artisan): a free address in the
     * browser would make a stolen operator login a way onto the server.
     *
     * @return list<string>
     */
    public function allowedRepositories(): array
    {
        $configured = config('instances.versions_repositories');
        $repositories = array_map('trim', explode(',', is_string($configured) ? $configured : ''));

        try {
            $repositories[] = $this->origin();
        } catch (RuntimeException) {
            // no git checkout: only the configured ones
        }

        return array_values(array_unique(array_filter($repositories, static fn (string $repository): bool => $repository !== '')));
    }

    /**
     * Whether a fetch is going on (its temporary folder is there).
     */
    public function inProgress(): bool
    {
        return (glob($this->versions->root() . '/.fetching-*', GLOB_ONLYDIR) ?: []) !== [];
    }

    /**
     * The last lines of the fetch log.
     */
    public function log(int $lines = 40): string
    {
        $log = $this->logFile();

        if (!is_file($log)) {
            return '';
        }

        $all = explode("\n", rtrim((string) file_get_contents($log)));

        return implode("\n", array_slice($all, -$lines));
    }

    public function logFile(): string
    {
        return $this->logFile ?? storage_path('logs/versions.log');
    }

    /**
     * Fetches the given tag, branch or commit (the newest tag when none) of
     * the repository (the origin of this checkout when none) into
     * <versions path>/<version>. Returns what was done, one line each.
     *
     * @return list<string>
     */
    public function fetch(?string $ref, ?string $repository): array
    {
        $repository = $repository ?? $this->origin();
        $ref = $ref ?? $this->newestTag($repository);
        $root = $this->versions->root();
        $temporary = $root . '/.fetching-' . bin2hex(random_bytes(6));

        if (!is_dir($root) && !mkdir($root, 0o750, true) && !is_dir($root)) {
            throw new RuntimeException('Cannot create ' . $root);
        }

        $done = ['Fetching ' . $ref . ' of ' . $repository . '.'];

        try {
            $this->checkout($repository, $ref, $temporary);
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
        $temporary = $link . '.' . bin2hex(random_bytes(6));

        if (!symlink($directory, $temporary) || !rename($temporary, $link)) {
            if (is_link($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException('Cannot point ' . $link . ' at ' . $directory);
        }

        return $directory;
    }

    /**
     * The code at a tag, a branch or a commit: without the history when the
     * server hands the one commit out (tags, branches, full hashes), else
     * with it (a short hash, or a server that gives only branch tips).
     */
    private function checkout(string $repository, string $ref, string $directory): void
    {
        if (!mkdir($directory, 0o750, true)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }

        $this->run(['git', 'init', '-q'], $directory);
        $this->run(['git', 'remote', 'add', 'origin', $repository], $directory);

        try {
            $this->run(['git', 'fetch', '--quiet', '--depth', '1', 'origin', $ref], $directory);
            $this->run(['git', 'checkout', '-q', '--detach', 'FETCH_HEAD'], $directory);
        } catch (RuntimeException) {
            $this->run(['git', 'fetch', '--quiet', '--tags', 'origin'], $directory);
            $this->run(['git', 'checkout', '-q', '--detach', $ref], $directory);
        }
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
            throw new RuntimeException('The repository has no tags; name a tag, a branch or a commit.');
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
