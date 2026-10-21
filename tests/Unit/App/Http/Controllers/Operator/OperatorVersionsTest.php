<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Operator;

use App\Http\Controllers\Operator\UniversesController;
use App\Http\Controllers\Operator\VersionsController;
use App\Models\Operator;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\VersionFetcher;
use App\Services\Instances\Versions;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Process\Process;
use Tests\DatabaseTestCase;

/**
 * In the browser an operator sees the versions of the code on the server
 * and who plays on them, fetches a new one from git (in the background,
 * with its log), lets a version serve, deletes the unused ones, moves a
 * universe to a newer version and creates a universe on an older one.
 * Each change is logged.
 */
#[CoversClass(VersionsController::class)]
#[CoversClass(UniversesController::class)]
#[CoversClass(VersionFetcher::class)]
class OperatorVersionsTest extends DatabaseTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private string $folder;

    private InstanceRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0', 'operators.web' => true, 'operators.allowed_ips' => '']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);

        $this->folder = sys_get_temp_dir() . '/xgp-op-versions-' . getmypid();
        File::deleteDirectory($this->folder);
        File::makeDirectory($this->folder . '/versions', 0o755, true);
        File::makeDirectory($this->folder . '/source', 0o755, true);
        file_put_contents($this->folder . '/source/.env', 'APP_KEY=x');
        $this->stubVersion('3.9.0');
        $this->stubVersion('3.8.0');

        $this->registry = app(InstanceRegistry::class);
        $versions = new Versions($this->registry, $this->folder . '/versions', $this->folder . '/source', $this->folder . '/map.php');
        $this->app->instance(Versions::class, $versions);
        $this->app->instance(VersionFetcher::class, new VersionFetcher($versions, base_path(), $this->folder . '/fetch.log'));

        $operator = new Operator(['email' => 'ops@example.com', 'password' => 'correct horse battery', 'created_at' => time()]);
        app(TwoFactor::class)->enable($operator, self::SECRET, $this->code(-60), time() - 60);
        $this->post('/operator/login', ['email' => 'ops@example.com', 'password' => 'correct horse battery']);
        $this->post('/operator/code', ['code' => $this->code()])->assertRedirect(route('operator.universes'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function testTheVersionsAndWhoPlaysOnThemAreListed(): void
    {
        $this->register('old', '3.9.0');
        $this->register('gone', '3.7.0');
        $this->register('fresh', '');

        $response = $this->get('/operator/versions')->assertOk();

        $response->assertSee('3.9.0')->assertSee('3.8.0')->assertSee('4.0.0')->assertSee('running')->assertSee('missing')->assertSee('3.7.0');
        $response->assertSee('old')->assertSee('gone')->assertSee('fresh');
        // the universes that could move, with the versions above theirs
        $response->assertSee('id="upgrade-old"', false)->assertSee('id="upgrade-gone"', false)->assertDontSee('id="upgrade-fresh"', false);
        // not laid out for switching by the folder "current": no such button
        $response->assertDontSee('Serve</button>', false);
        $this->get('/operator/universes')->assertOk()->assertSee('/operator/versions', false);
    }

    public function testANewVersionIsFetchedInTheBackgroundAndTheLogShown(): void
    {
        if ((new Process(['git', '--version']))->run() !== 0) {
            $this->markTestSkipped('git is needed.');
        }

        $repository = $this->repository('4.1.0');

        $this->post('/operator/versions/fetch', ['ref' => 'bad ref', 'repo' => $repository])->assertSessionHasErrors('ref');
        $this->post('/operator/versions/fetch', ['ref' => 'v4.1.0', 'repo' => 'ftp://nowhere'])->assertSessionHasErrors('repo');

        $this->post('/operator/versions/fetch', ['ref' => 'v4.1.0', 'repo' => $repository])
            ->assertRedirect('/operator/versions')
            ->assertSessionHas('status', __('operator/operator.op_fetch_started'));

        // the fetch is a process of its own: wait for it
        for ($i = 0; $i < 120 && !is_file($this->folder . '/versions/4.1.0/.release'); $i++) {
            usleep(500_000);
        }

        $this->assertFileExists($this->folder . '/versions/4.1.0/.release', (string) @file_get_contents($this->folder . '/fetch.log'));
        $this->get('/operator/versions')->assertOk()->assertSee('4.1.0')->assertSee('Version 4.1.0 is in');
        $this->assertSame('version_fetch', DB::connection('central')->table('operator_events')->where('subject', 'v4.1.0')->value('action'));
    }

    public function testWithoutTheFolderLayoutNoVersionIsSwitchedTo(): void
    {
        $this->post('/operator/versions/use', ['version' => '3.9.0'])->assertSessionHasErrors('version');
        $this->assertFalse(is_link($this->folder . '/versions/current'));
    }

    public function testAVersionServesWhenTheServerIsLaidOutByFolders(): void
    {
        // the running code is a folder under the versions path
        $this->stubVersion('4.0.0');
        $versions = new Versions($this->registry, $this->folder . '/versions', $this->folder . '/versions/4.0.0', $this->folder . '/map.php');
        $this->app->instance(Versions::class, $versions);
        $this->app->instance(VersionFetcher::class, new VersionFetcher($versions, $this->folder . '/versions/4.0.0', $this->folder . '/fetch.log'));

        $this->get('/operator/versions')->assertOk()->assertSee('Serve</button>', false);
        $this->post('/operator/versions/use', ['version' => '3.9.0'])
            ->assertRedirect('/operator/versions')
            ->assertSessionHas('status', __('operator/operator.op_used', ['version' => '3.9.0']));

        $this->assertSame($this->folder . '/versions/3.9.0', readlink($this->folder . '/versions/current'));
        $this->assertSame('version_used', DB::connection('central')->table('operator_events')->where('subject', '3.9.0')->value('action'));
        $this->post('/operator/versions/use', ['version' => '9.9.9'])->assertSessionHasErrors('version');
    }

    public function testTheUnusedVersionsArePruned(): void
    {
        $this->register('old', '3.9.0');

        $this->post('/operator/versions/prune')->assertRedirect('/operator/versions')->assertSessionHas('status', __('operator/operator.op_pruned', ['versions' => '3.8.0']));

        $this->assertDirectoryDoesNotExist($this->folder . '/versions/3.8.0');
        $this->assertDirectoryExists($this->folder . '/versions/3.9.0');
        $this->post('/operator/versions/prune')->assertSessionHas('status', __('operator/operator.op_pruned_none'));
        $this->assertSame('versions_pruned', DB::connection('central')->table('operator_events')->where('subject', '3.8.0')->value('action'));
    }

    public function testAUniverseMovesToANewerVersionNeverBack(): void
    {
        $this->register('old', '3.9.0');
        $provisioner = $this->createMock(InstanceProvisioner::class);
        $provisioner->expects($this->once())->method('migrate')->willReturn("Nothing to migrate.\n");
        $this->app->instance(InstanceProvisioner::class, $provisioner);

        $this->post('/operator/universes/old/upgrade', ['version' => '3.8.0'])->assertRedirect('/operator/versions')->assertSessionHasErrors('upgrade');
        $this->post('/operator/universes/old/upgrade', ['version' => '4.0.0'])
            ->assertRedirect('/operator/versions')
            ->assertSessionHas('status', __('operator/operator.op_upgraded', ['name' => 'Old', 'version' => '4.0.0']));

        $this->assertSame('4.0.0', $this->registry->find('old')?->version);
        $this->assertSame('universe_upgraded', DB::connection('central')->table('operator_events')->where('subject', 'old')->value('action'));
        $this->post('/operator/universes/nowhere/upgrade', ['version' => '4.0.0'])->assertNotFound();
    }

    public function testAUniverseIsCreatedOnAnOlderVersionByThatVersionsCode(): void
    {
        $this->get('/operator/universes/create')->assertOk()->assertSee('<option value="3.9.0"', false)->assertSee('4.0.0 (running)');

        $this->post('/operator/universes', [
            'slug' => 'op-old', 'name' => 'Old One', 'mode' => 'play2win', 'payments' => [], 'bots' => 2, 'version' => '3.9.0',
            'admin_name' => 'Mayor', 'admin_email' => 'mayor@example.com', 'admin_password' => 'secret-password',
        ])->assertRedirect(route('operator.universes.edit', 'op-old'));

        // what the older version was asked, the password through the environment
        $call = (string) file_get_contents($this->folder . '/versions/3.9.0/last-call');
        $this->assertStringContainsString('xgp:instance:create op-old --name=Old One --mode=play2win --payments= --bots=2 --ends= --admin-name=Mayor --admin-email=mayor@example.com --no-interaction', $call);
        $this->assertStringContainsString('password=secret-password', $call);
        $this->assertSame('3.9.0', DB::connection('central')->table('operator_events')->where('action', 'universe_created')->value('subject') === 'op-old' ? '3.9.0' : '');

        $this->post('/operator/universes', ['slug' => 'op-new', 'name' => 'New', 'mode' => 'play2win', 'version' => '2.0.0', 'admin_name' => 'M', 'admin_email' => 'm@example.com', 'admin_password' => 'secret-password'])
            ->assertSessionHasErrors('version');
    }

    private function register(string $slug, string $version): void
    {
        $this->registry->register(new Instance($slug, ucfirst($slug), 'xgp_uni_' . $slug, Instance::PLAY2WIN, Instance::OPEN, time(), null, null, '', $version));
    }

    /**
     * A version's folder with an artisan that says, and writes down, what
     * it was asked.
     */
    private function stubVersion(string $version): void
    {
        File::makeDirectory($this->folder . '/versions/' . $version . '/public', 0o755, true);
        File::makeDirectory($this->folder . '/versions/' . $version . '/vendor', 0o755, true);
        file_put_contents($this->folder . '/versions/' . $version . '/vendor/autoload.php', '<?php');
        file_put_contents($this->folder . '/versions/' . $version . '/public/index.php', '<?php');
        file_put_contents(
            $this->folder . '/versions/' . $version . '/artisan',
            '<?php $call = implode(" ", array_slice($argv, 1)) . " password=" . getenv("XGP_ADMIN_PASSWORD"); file_put_contents(__DIR__ . "/last-call", $call); fwrite(STDOUT, "stub ' . $version . ': " . $call);'
        );
    }

    /**
     * A git repository with the game's version file and a tag.
     */
    private function repository(string $version): string
    {
        $repository = $this->folder . '/repo';
        File::makeDirectory($repository . '/config', 0o755, true);
        file_put_contents($repository . '/config/version.php', "<?php return ['files' => '" . $version . "'];");
        file_put_contents($repository . '/artisan', '<?php');
        $env = ['GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@example.com', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@example.com'];

        foreach ([['init', '-q', '-b', 'master'], ['add', '.'], ['commit', '-q', '-m', 'release'], ['tag', 'v' . $version]] as $arguments) {
            $process = new Process(['git', ...$arguments], $repository, $env);
            $this->assertSame(0, $process->run(), $process->getErrorOutput());
        }

        return $repository;
    }

    private function code(int $offset = 0): string
    {
        $totp = new Totp();

        return $totp->code(self::SECRET, $totp->step(time() + $offset));
    }
}
