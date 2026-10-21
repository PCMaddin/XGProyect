<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Instances;

use App\Console\Commands\Instances\FetchVersion;
use App\Console\Commands\Instances\UseVersion;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\VersionFetcher;
use App\Services\Instances\Versions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Process\Process;
use Tests\DatabaseTestCase;

/**
 * On a server without Docker a new version comes from git into a folder of
 * its own under the versions path: cloned at a tag (the newest by default)
 * or branch, settings linked, release marked, caches built; a version that
 * is there already is never cloned over. xgp:versions:use then points the
 * folder "current" (the web root) at it and lets it write the router map.
 */
#[CoversClass(VersionFetcher::class)]
#[CoversClass(FetchVersion::class)]
#[CoversClass(UseVersion::class)]
class VersionFetcherTest extends DatabaseTestCase
{
    private string $folder;

    private string $repository;

    protected function setUp(): void
    {
        parent::setUp();

        if ((new Process(['git', '--version']))->run() !== 0) {
            $this->markTestSkipped('git is needed.');
        }

        config(['version.files' => '4.0.0']);
        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);

        $this->folder = sys_get_temp_dir() . '/xgp-fetch-' . getmypid();
        File::deleteDirectory($this->folder);
        File::makeDirectory($this->folder . '/versions', 0o755, true);
        File::makeDirectory($this->folder . '/source', 0o755, true);
        file_put_contents($this->folder . '/source/.env', 'APP_KEY=x');
        $this->repository = $this->folder . '/repo';
        $this->repository('4.1.0', 'v4.1.0');

        $versions = new Versions(app(InstanceRegistry::class), $this->folder . '/versions', $this->folder . '/source', $this->folder . '/map.php');
        $this->app->instance(Versions::class, $versions);
        $this->app->instance(VersionFetcher::class, new VersionFetcher($versions, $this->folder . '/source'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function testTheNewestTagIsClonedIntoAFolderOfItsVersion(): void
    {
        $this->git(['tag', 'v3.9.9']);

        $this->command('xgp:versions:fetch', ['--repo' => $this->repository])
            ->expectsOutputToContain('Fetching v4.1.0 of ' . $this->repository)
            ->expectsOutputToContain('Version 4.1.0 (')
            ->expectsOutputToContain('No composer.json: nothing to install.')
            ->expectsOutputToContain('Configuration, routes and views cached.')
            ->expectsOutputToContain('Serve it with: php artisan xgp:versions:use 4.1.0')
            ->assertSuccessful();

        $clone = $this->folder . '/versions/4.1.0';
        $this->assertDirectoryExists($clone . '/.git');
        $this->assertFileExists($clone . '/config/version.php');
        $this->assertSame(trim($this->git(['rev-parse', 'HEAD'])) . "\n", file_get_contents($clone . '/.release'));
        $this->assertTrue(is_link($clone . '/.env'));
        $this->assertDirectoryExists($clone . '/storage/framework/views');
        $this->assertSame(['4.0.0', '4.1.0'], app(Versions::class)->available());

        // never cloned over
        $this->command('xgp:versions:fetch', ['ref' => 'v4.1.0', '--repo' => $this->repository])
            ->expectsOutputToContain('is on the server already')
            ->assertFailed();
        $this->assertDirectoryDoesNotExist($this->folder . '/versions/.fetching-' . getmypid());
    }

    public function testABranchIsClonedByName(): void
    {
        $this->git(['checkout', '-q', '-b', 'next']);
        file_put_contents($this->repository . '/config/version.php', "<?php return ['files' => '4.2.0'];");
        $this->git(['commit', '-q', '-am', 'next']);

        $this->command('xgp:versions:fetch', ['ref' => 'next', '--repo' => $this->repository])->expectsOutputToContain('Version 4.2.0 (')->assertSuccessful();

        $this->assertDirectoryExists($this->folder . '/versions/4.2.0');
    }

    public function testACommitIsFetchedByItsHashLongOrShort(): void
    {
        $tagged = trim($this->git(['rev-parse', 'HEAD']));
        file_put_contents($this->repository . '/config/version.php', "<?php return ['files' => '4.1.1'];");
        $this->git(['commit', '-q', '-am', 'fix']);
        $fix = trim($this->git(['rev-parse', 'HEAD']));
        file_put_contents($this->repository . '/config/version.php', "<?php return ['files' => '4.2.0'];");
        $this->git(['commit', '-q', '-am', 'later']);

        // a commit behind the branch tip, by its full hash
        $this->command('xgp:versions:fetch', ['ref' => $fix, '--repo' => $this->repository])->expectsOutputToContain('Version 4.1.1 (')->assertSuccessful();
        $this->assertSame($fix . "\n", file_get_contents($this->folder . '/versions/4.1.1/.release'));

        // and by a short one
        $this->command('xgp:versions:fetch', ['ref' => substr($tagged, 0, 8), '--repo' => $this->repository])->expectsOutputToContain('Version 4.1.0 (')->assertSuccessful();
        $this->assertSame($tagged . "\n", file_get_contents($this->folder . '/versions/4.1.0/.release'));

        $this->command('xgp:versions:fetch', ['ref' => 'deadbeef', '--repo' => $this->repository])->expectsOutputToContain('git checkout failed')->assertFailed();
        $this->assertSame([], glob($this->folder . '/versions/.fetching-*') ?: []);
    }

    public function testWithoutTagsARefMustBeNamedAndACloneWithoutTheGameIsRefused(): void
    {
        $this->git(['tag', '-d', 'v4.1.0']);
        $this->command('xgp:versions:fetch', ['--repo' => $this->repository])->expectsOutputToContain('no tags')->assertFailed();

        $this->git(['rm', '-q', 'config/version.php']);
        $this->git(['commit', '-q', '-m', 'not the game']);
        $this->command('xgp:versions:fetch', ['ref' => 'master', '--repo' => $this->repository])->expectsOutputToContain('not the game')->assertFailed();
        $this->assertSame([], glob($this->folder . '/versions/.fetching-*') ?: []);
    }

    public function testTheFolderCurrentPointsAtTheVersionInUse(): void
    {
        $this->command('xgp:versions:fetch', ['--repo' => $this->repository])->assertSuccessful();
        $link = $this->folder . '/versions/current';

        $this->command('xgp:versions:use', ['version' => '9.9.9'])->expectsOutputToContain('not on the server')->assertFailed();
        $this->assertFalse(is_link($link));

        $this->command('xgp:versions:use', ['version' => '4.1.0'])
            // the version's own artisan wrote the map
            ->expectsOutputToContain('stub 4.1.0: xgp:versions:sync')
            ->expectsOutputToContain('current -> ' . $this->folder . '/versions/4.1.0')
            ->assertSuccessful();

        $this->assertSame($this->folder . '/versions/4.1.0', readlink($link));
        // the link is no version of its own
        $this->assertSame(['4.0.0', '4.1.0'], app(Versions::class)->available());
    }

    /**
     * A repository with the game's version file, a stub artisan that says
     * what it was asked, and a vendor/ so the caches are "built".
     */
    private function repository(string $version, string $tag): void
    {
        File::makeDirectory($this->repository . '/config', 0o755, true);
        File::makeDirectory($this->repository . '/vendor', 0o755, true);
        file_put_contents($this->repository . '/config/version.php', "<?php return ['files' => '" . $version . "'];");
        file_put_contents($this->repository . '/vendor/autoload.php', '<?php');
        file_put_contents($this->repository . '/artisan', '<?php fwrite(STDOUT, "stub ' . $version . ': " . implode(" ", array_slice($argv, 1)));');
        $this->git(['init', '-q', '-b', 'master']);
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.com', 'add', '.']);
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-q', '-m', 'release']);
        $this->git(['tag', $tag]);
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->repository, ['GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@example.com', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@example.com']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return $process->getOutput();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
