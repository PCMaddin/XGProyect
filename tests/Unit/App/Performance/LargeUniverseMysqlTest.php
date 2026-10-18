<?php

declare(strict_types=1);

namespace Tests\Unit\App\Performance;

use App\Libraries\Statistics\BulkStatistics;
use App\Libraries\UpdatesLibrary;
use App\Libraries\Users;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Tests\MysqlTestCase;

/**
 * The statistics rebuild on MariaDB: started by one page view only, ranks
 * through the joined updates, and the highscore read page by page by rank.
 */
#[CoversClass(UpdatesLibrary::class)]
#[CoversClass(BulkStatistics::class)]
class LargeUniverseMysqlTest extends MysqlTestCase
{
    /** @var list<User> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);

        foreach (['Alpha', 'Bravo', 'Charlie'] as $number => $name) {
            $player = app(RegistrationService::class)->register(['name' => $name, 'email' => strtolower($name) . '@example.com', 'password' => 'secret-password']);
            $this->assertInstanceOf(User::class, $player);
            $player = User::findOrFail($player->id);
            DB::table('buildings')->where('building_planet_id', $player->home_planet_id)->update(['building_metal_mine' => 10 + 5 * $number]);
            $this->players[] = $player;
        }
    }

    public function testThePageViewThatClaimsTheRebuildRunsIt(): void
    {
        $this->statisticsDue();

        new UpdatesLibrary();

        // Charlie has the largest mine
        $this->assertSame([3, 2, 1], array_map(fn (User $player): int => $this->stat($player, 'total_rank'), $this->players));
        $this->assertSame(3, app(SettingsService::class)->getInt('stat_users'));
        $this->assertEqualsWithDelta(time(), $this->option('stat_last_update'), 5);
    }

    public function testNoRebuildWhenAnotherPageViewClaimedItFirst(): void
    {
        $this->statisticsDue();
        $done = false;
        // between this page's look at the time stamp and its claim, another page claims
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$done): void {
            if (!$done && str_contains($query, 'update `xgp_options`') && in_array('stat_last_update', $bindings, true)) {
                $done = true;
                DB::table('options')->where('name', 'stat_last_update')->update(['value' => (string) (time() + 3600)]);
            }
        });

        new UpdatesLibrary();

        $this->assertTrue($done);
        $this->assertSame([0, 0, 0], array_map(fn (User $player): int => $this->stat($player, 'total_rank'), $this->players));
    }

    public function testTheHighscoreReadsThePageByRank(): void
    {
        [$alpha, $bravo, $charlie] = $this->players;
        $this->rank($alpha, 10050);
        $this->rank($bravo, 50);
        $this->rank($charlie, 10001);

        $page = $this->asPlayer($alpha)->get('/game.php?page=highscore&range=10001')->assertOk();

        $page->assertSeeInOrder(['Charlie', 'Alpha'])->assertDontSee('Bravo');
        $this->asPlayer($alpha)->get('/game.php?page=highscore')->assertOk()->assertSee('Bravo')->assertDontSee('Charlie');
    }

    public function testASessionFromBeforeIsAcceptedAndThenFingerprinted(): void
    {
        $player = $this->players[0];

        $this->asPlayer($player)->get('/game.php?page=overview')->assertOk()->assertSessionHas('user_password', static fn (string $value): bool => str_starts_with($value, 'hmac:'));
    }

    private function statisticsDue(): void
    {
        DB::table('options')->where('name', 'stat_last_update')->update(['value' => '0']);
        // the options as a new page view reads them
        $this->app->forgetScopedInstances();
    }

    private function rank(User $player, int $rank): void
    {
        DB::table('users_statistics')->where('user_statistic_user_id', $player->id)->update(['user_statistic_total_rank' => $rank]);
    }

    private function stat(User $player, string $column): int
    {
        $value = DB::table('users_statistics')->where('user_statistic_user_id', $player->id)->value('user_statistic_' . $column);

        return is_numeric($value) ? (int) $value : -1;
    }

    private function option(string $name): int
    {
        $value = DB::table('options')->where('name', $name)->value('value');

        return is_numeric($value) ? (int) $value : -1;
    }

    private function asPlayer(User $player): static
    {
        (new ReflectionProperty(Users::class, 'instance'))->setValue(null, null);
        $password = User::findOrFail($player->id)->getAuthPassword();

        return $this->actingAs($player)->withSession([
            'user_id' => $player->id,
            'user_password' => Hash::make($password . '-test-secret'),
        ]);
    }
}
