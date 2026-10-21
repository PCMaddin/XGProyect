<?php

declare(strict_types=1);

namespace App\Services\Instances;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * The versions of the code on the server, one directory per version under
 * the versions path (config instances.versions_path): every version from
 * the oldest a universe still plays on up to the one that runs. A universe
 * is pinned to the version it was created with (instances.version) until
 * xgp:instance:upgrade moves it; its pages come from that version's
 * directory (public/index.php hands the request over, see
 * bootstrap/version-router.php) and so do its tick, its cheat scan and its
 * migrations (that version's artisan, run as a process).
 *
 * xgp:versions:sync copies the running code into its directory and writes
 * the map the router reads; xgp:versions --prune deletes the directories
 * below the oldest version in use.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class Versions
{
    /** a version is a directory name: nothing that leaves the versions path */
    public const PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+-]{0,31}$/';

    /** not copied into a version's directory: written at runtime, or bound to this checkout */
    private const SKIP = ['storage', 'node_modules', '.git', 'bootstrap/cache/config.php', 'bootstrap/cache/packages.php', 'bootstrap/cache/services.php', '.env'];

    /** what a version's directory needs to hold once copied */
    private const STORAGE = ['storage/app/backups', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'];

    /** an artisan call of another version: a tick, a scan, the migrations */
    private const TIMEOUT = 3600;

    public function __construct(
        private InstanceRegistry $registry,
        private ?string $root = null,
        private ?string $source = null,
        private ?string $mapFile = null,
    ) {
    }

    /**
     * The version of the code that runs.
     */
    public function current(): string
    {
        $version = config('version.files');

        return is_string($version) ? $version : '';
    }

    /**
     * Where the directories of the versions lie.
     */
    public function root(): string
    {
        if ($this->root === null) {
            $configured = config('instances.versions_path');
            $this->root = is_string($configured) && $configured !== '' ? rtrim($configured, '/') : storage_path('versions');
        }

        return $this->root;
    }

    /**
     * The versions on the server, oldest first: the directories under the
     * versions path that hold a game, and the one that runs.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $versions = [$this->current()];

        foreach (is_dir($this->root()) ? scandir($this->root()) ?: [] : [] as $entry) {
            // not the link "current" (xgp:versions:use), only the folders
            if (preg_match(self::PATTERN, $entry) === 1 && !is_link($this->root() . '/' . $entry) && is_file($this->root() . '/' . $entry . '/artisan')) {
                $versions[] = $entry;
            }
        }

        $versions = array_values(array_unique(array_filter($versions)));
        usort($versions, static fn (string $one, string $other): int => version_compare($one, $other));

        return $versions;
    }

    /**
     * The directory of a version: this checkout for the running one, else
     * its directory under the versions path; null when it is not there.
     */
    public function directory(string $version): ?string
    {
        if ($version === '' || $version === $this->current()) {
            return $this->source ?? base_path();
        }

        $directory = $this->root() . '/' . $version;

        return preg_match(self::PATTERN, $version) === 1 && !is_link($directory) && is_file($directory . '/artisan') ? $directory : null;
    }

    /**
     * Which universes play on which version (the running one for those that
     * name none), the version in use longest first.
     *
     * @return array<string, list<string>> version => slugs
     */
    public function inUse(): array
    {
        $use = [];

        foreach ($this->registry->all() as $instance) {
            $use[$instance->version !== '' ? $instance->version : $this->current()][] = $instance->slug;
        }

        uksort($use, static fn (string $one, string $other): int => version_compare($one, $other));

        return $use;
    }

    /**
     * The oldest version a universe still plays on (the running one without universes).
     */
    public function oldestInUse(): string
    {
        $versions = array_keys($this->inUse());

        return $versions === [] ? $this->current() : $versions[0];
    }

    /**
     * Runs the work for a universe with the code it plays on: here when it
     * plays on this version, else as the given artisan command of its
     * version's directory. Returns what was printed.
     *
     * @param list<string>     $arguments
     * @param callable(): mixed $own
     */
    public function runFor(Instance $instance, string $command, array $arguments, callable $own): string
    {
        if ($instance->runsOn($this->current())) {
            $result = $own();

            return is_string($result) ? $result : '';
        }

        return $this->artisan($instance->version, $command, $arguments);
    }

    /**
     * An artisan command of another version's directory, run as a process
     * with this environment (the same .env or container variables).
     *
     * @param list<string> $arguments
     */
    public function artisan(string $version, string $command, array $arguments = []): string
    {
        $directory = $this->directory($version);

        if ($directory === null) {
            throw new RuntimeException('The version ' . $version . ' is not on the server (' . $this->root() . '); xgp:versions lists the ones there.');
        }

        $process = new Process([PHP_BINARY, $directory . '/artisan', $command, ...$arguments, '--no-interaction'], $directory, null, null, self::TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Version ' . $version . ', ' . $command . ' failed: ' . trim($process->getErrorOutput() . "\n" . $process->getOutput()));
        }

        return $process->getOutput();
    }

    /**
     * Puts the running code into its directory under the versions path
     * (once per version) and writes the map for the router. Returns what
     * was done, one line each.
     *
     * @return list<string>
     */
    public function sync(): array
    {
        $version = $this->current();

        if ($version === '' || preg_match(self::PATTERN, $version) !== 1) {
            throw new RuntimeException('The version of the code (config version.files) must be a directory name: ' . $version);
        }

        $done = [$this->keepOwnDirectory($version)];

        // the other versions read this environment too: their cached
        // configuration (database password, mail server) is renewed here
        foreach ($this->available() as $other) {
            if ($other !== $version) {
                $done[] = $this->cacheConfiguration($other);
            }
        }

        $this->writeMap();
        $done[] = 'Router map written (' . count($this->pinned()) . ' universe(s) on other versions).';

        return $done;
    }

    private function keepOwnDirectory(string $version): string
    {
        $directory = $this->root() . '/' . $version;
        $release = getenv('XGP_RELEASE');
        $release = is_string($release) ? $release : '';

        if (!is_file($directory . '/artisan')) {
            $this->copy($this->source ?? base_path(), $directory, $release);

            return 'Version ' . $version . ' copied to ' . $directory . '.';
        }

        $there = is_file($directory . '/.release') ? trim((string) file_get_contents($directory . '/.release')) : '';

        if ($there === $release || $release === '') {
            return 'Version ' . $version . ' is in ' . $directory . '.';
        }

        return 'WARNING: ' . $directory . ' holds release ' . ($there === '' ? '(unknown)' : $there) . ', this is ' . $release
            . ': the version was not raised (config/version.php). Universes pinned to ' . $version . ' keep the code there.';
    }

    private function cacheConfiguration(string $version): string
    {
        try {
            $this->artisan($version, 'config:cache');

            return 'Version ' . $version . ': configuration cached.';
        } catch (RuntimeException $e) {
            return 'WARNING: ' . $e->getMessage();
        }
    }

    /**
     * Deletes the directories of the versions below the oldest in use; the
     * running one stays whatever is in use.
     *
     * @return list<string> the versions deleted
     */
    public function prune(): array
    {
        $oldest = $this->oldestInUse();
        $deleted = [];

        foreach ($this->available() as $version) {
            if ($version === $this->current() || version_compare($version, $oldest, '>=')) {
                continue;
            }

            $this->deleteDirectory($this->root() . '/' . $version);
            $deleted[] = $version;
        }

        return $deleted;
    }

    /**
     * The map public/index.php reads before the framework starts: the
     * universes that play on another version, with where that version
     * lies. Without any, the map is removed.
     */
    public function writeMap(): void
    {
        $file = $this->mapFile ?? storage_path('framework/versions.php');
        $pinned = $this->pinned();

        if ($pinned === []) {
            if (is_file($file)) {
                unlink($file);
            }

            return;
        }

        $map = ['current' => $this->current(), 'root' => $this->root(), 'universes' => $pinned];
        $temporary = $file . '.' . getmypid() . '.tmp';
        file_put_contents($temporary, "<?php\n\n// written by xgp:versions:sync and xgp:instance:upgrade: the universes on other versions\nreturn " . var_export($map, true) . ";\n");
        rename($temporary, $file);
    }

    /**
     * @return array<string, string> slug => version, for the universes on another version
     */
    private function pinned(): array
    {
        $pinned = [];

        foreach ($this->registry->all() as $instance) {
            if ($instance->hasDatabase() && !$instance->runsOn($this->current()) && preg_match(self::PATTERN, $instance->version) === 1) {
                $pinned[$instance->slug] = $instance->version;
            }
        }

        return $pinned;
    }

    /**
     * Copies the code without what is written at runtime, into a temporary
     * directory first: a half copy is never taken for a version.
     */
    private function copy(string $from, string $to, string $release): void
    {
        $temporary = $this->root() . '/.copying-' . basename($to) . '-' . getmypid();
        $this->deleteDirectory($temporary);

        if (!is_dir($this->root()) && !mkdir($this->root(), 0o750, true) && !is_dir($this->root())) {
            throw new RuntimeException('Cannot create ' . $this->root());
        }

        $from = rtrim($from, '/');

        try {
            if (!mkdir($temporary, 0o750, true)) {
                throw new RuntimeException('Cannot create ' . $temporary);
            }

            $this->copyTree($from, $temporary);
        } catch (RuntimeException $e) {
            $this->deleteDirectory($temporary);

            throw $e;
        }

        $this->finish($from, $temporary, $release);

        // another container copied the same version meanwhile: its copy counts
        if (is_dir($to)) {
            $this->deleteDirectory($temporary);
        } elseif (!rename($temporary, $to)) {
            $this->deleteDirectory($temporary);

            throw new RuntimeException('Cannot move the copy to ' . $to);
        }
    }

    /**
     * What a copied version needs besides the code: the storage folders,
     * its release mark, and the settings.
     */
    private function finish(string $from, string $copy, string $release): void
    {
        foreach (self::STORAGE as $directory) {
            if (!is_dir($copy . '/' . $directory)) {
                mkdir($copy . '/' . $directory, 0o770, true);
            }
        }

        file_put_contents($copy . '/.release', $release . "\n");

        // the settings stay one file: a changed password reaches every version
        if (is_file($from . '/.env')) {
            symlink($from . '/.env', $copy . '/.env');
        }
    }

    private function copyTree(string $from, string $to): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => !in_array(substr($file->getPathname(), strlen($from) + 1), self::SKIP, true),
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            $target = $to . substr($file->getPathname(), strlen($from));

            if ($file->isDir()) {
                mkdir($target, 0o750, true);
            } elseif (!copy($file->getPathname(), $target)) {
                throw new RuntimeException('Cannot copy ' . $file->getPathname() . ' to ' . $target);
            }
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }
}
