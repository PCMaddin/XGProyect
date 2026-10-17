<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Game;

use App\Http\Controllers\Game\GalaxyController;
use App\Http\Controllers\Game\PhalanxController;
use App\Libraries\Users;
use App\Models\User;
use App\Services\Game\SystemEspionageService;
use App\Services\RegistrationService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Tests\MysqlTestCase;

/**
 * The galaxy tools of the alliance classes (OGame 8) and the wreck fields
 * in the galaxy view: the system phalanx of researchers (40.000
 * deuterium, all planets of the system), the system espionage of warriors
 * (probes to every target, skipping the ones spied on recently).
 */
#[CoversClass(GalaxyController::class)]
#[CoversClass(PhalanxController::class)]
#[CoversClass(SystemEspionageService::class)]
class GalaxyClassToolsMysqlTest extends MysqlTestCase
{
    private User $player;

    private User $neighbour;

    private int $allianceId;

    /** @var array{galaxy: int, system: int, planet: int} */
    private array $home;

    private int $neighbourPlanet;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);

        $this->player = $this->register('Player');
        $this->neighbour = $this->register('Neighbour');

        $home = DB::table('planets')->where('planet_user_id', $this->player->id)->first(['planet_galaxy', 'planet_system', 'planet_planet']);
        $this->assertNotNull($home);
        $this->home = ['galaxy' => (int) $home->planet_galaxy, 'system' => (int) $home->planet_system, 'planet' => (int) $home->planet_planet];

        // the neighbour lives in the same system, inactive for two weeks
        $position = $this->home['planet'] === 15 ? 14 : $this->home['planet'] + 1;
        DB::table('planets')->where('planet_user_id', $this->neighbour->id)->update(['planet_galaxy' => $this->home['galaxy'], 'planet_system' => $this->home['system'], 'planet_planet' => $position, 'planet_name' => 'Neighbourhood']);
        DB::table('users')->where('id', $this->neighbour->id)->update(['onlinetime' => time() - 14 * 86400]);
        $planetId = DB::table('planets')->where('planet_user_id', $this->neighbour->id)->value('planet_id');
        $this->assertIsNumeric($planetId);
        $this->neighbourPlanet = (int) $planetId;

        DB::table('planets')->update(['planet_deuterium' => 1e6]);
        DB::table('ships')->update(['ship_espionage_probe' => 10]);

        $this->allianceId = DB::table('alliance')->insertGetId(['alliance_name' => 'Testers', 'alliance_tag' => 'TST', 'alliance_owner' => $this->player->id, 'alliance_register_time' => time(), 'alliance_ranks' => '[]']);
        DB::table('alliance_statistics')->insert(['alliance_statistic_alliance_id' => $this->allianceId]);
        DB::table('users')->where('id', $this->player->id)->update(['ally_id' => $this->allianceId, 'ally_rank_id' => 0]);
    }

    public function testResearchersScanTheWholeSystemFromTheirMoon(): void
    {
        $moon = $this->moonWithPhalanx();
        DB::table('fleets')->insert([
            'fleet_owner' => $this->player->id, 'fleet_mission' => 3, 'fleet_amount' => 1, 'fleet_array' => 'a:1:{i:202;i:1;}',
            'fleet_start_time' => time() + 3600, 'fleet_end_time' => time() + 7200, 'fleet_end_stay' => 0,
            'fleet_start_galaxy' => 9, 'fleet_start_system' => 9, 'fleet_start_planet' => 9, 'fleet_start_type' => 1,
            'fleet_end_galaxy' => $this->home['galaxy'], 'fleet_end_system' => $this->home['system'], 'fleet_end_planet' => $this->home['planet'] === 15 ? 14 : $this->home['planet'] + 1, 'fleet_end_type' => 1,
            'fleet_target_owner' => $this->neighbour->id, 'fleet_mess' => 0,
        ]);
        DB::table('planets')->insert(['planet_user_id' => $this->player->id, 'planet_name' => 'Origin', 'planet_type' => 1, 'planet_galaxy' => 9, 'planet_system' => 9, 'planet_planet' => 9]);
        $url = '/game.php?page=phalanx&mode=system&galaxy=' . $this->home['galaxy'] . '&system=' . $this->home['system'];

        // only for researchers
        $this->asPlayer()->post($url)->assertRedirect();
        $this->assertSame(1e6, $this->deuterium($moon));
        $this->assertStringNotContainsString(__('game/phalanx.px_system_phalanx'), (string) $this->asPlayer()->get('/game.php?page=galaxy&mode=0')->getContent());

        DB::table('alliance')->update(['alliance_class' => 2]);
        $this->assertStringContainsString(__('game/phalanx.px_system_phalanx'), (string) $this->asPlayer()->get('/game.php?page=galaxy&mode=0')->getContent());

        $page = (string) $this->asPlayer()->post($url)->getContent();
        $this->assertSame(1e6 - SYSTEM_PHALANX_COST, $this->deuterium($moon));
        $this->assertStringContainsString('Neighbourhood', $page);
        $this->assertStringContainsString('[' . $this->home['galaxy'] . ':' . $this->home['system'] . ':' . ($this->home['planet'] === 15 ? 14 : $this->home['planet'] + 1) . ']', $page);
        $this->assertStringContainsString('Origin', $page);
    }

    public function testWarriorsSpyOnTheWholeSystem(): void
    {
        $url = '/game.php?page=galaxy&mode=3&systemspy=true';
        $form = ['galaxy' => $this->home['galaxy'], 'system' => $this->home['system'], 'probes' => 3, 'targets' => 'both', 'activity' => 'inactive', 'skip_hours' => 2];

        // only for warriors
        $this->asPlayer()->post($url, $form);
        $this->assertSame(0, DB::table('fleets')->count());

        DB::table('alliance')->update(['alliance_class' => 3]);
        $page = (string) $this->asPlayer()->post($url, $form)->getContent();

        $fleet = DB::table('fleets')->first(['fleet_mission', 'fleet_array', 'fleet_target_owner']);
        $this->assertNotNull($fleet);
        $this->assertSame([6, 'a:1:{i:210;i:3;}', $this->neighbour->id], [(int) $fleet->fleet_mission, $fleet->fleet_array, (int) $fleet->fleet_target_owner]);
        $this->assertStringContainsString(sprintf(__('game/galaxy.gl_system_spy_result'), 1, 0), $page);
        $this->assertSame(1, DB::table('espionage_log')->where('planet_id', $this->neighbourPlanet)->count());

        // spied on within the last two hours: skipped; the active filter finds nobody
        $this->asPlayer()->post($url, $form);
        $this->asPlayer()->post($url, ['activity' => 'active', 'skip_hours' => 0] + $form);
        $this->assertSame(1, DB::table('fleets')->count());
        $probes = DB::table('ships')->where('ship_planet_id', $this->homePlanetId())->value('ship_espionage_probe');
        $this->assertIsNumeric($probes);
        $this->assertSame(7, (int) $probes);
    }

    public function testWreckFieldsShowInTheGalaxy(): void
    {
        $page = fn (): string => (string) $this->asPlayer()->get('/game.php?page=galaxy&mode=0')->getContent();
        $this->assertStringNotContainsString('wreck.jpg', $page());

        DB::table('wreck_fields')->insert(['planet_id' => $this->neighbourPlanet, 'user_id' => $this->neighbour->id, 'ships' => '{"207":12}', 'created_at' => time(), 'expires_at' => time() + 3600]);
        $this->assertStringContainsString('wreck.jpg', $page());

        // burnt up
        DB::table('wreck_fields')->update(['expires_at' => time() - 1]);
        $this->assertStringNotContainsString('wreck.jpg', $page());
    }

    private function moonWithPhalanx(): int
    {
        $moon = DB::table('planets')->insertGetId([
            'planet_user_id' => $this->player->id, 'planet_name' => 'Moon', 'planet_type' => 3, 'planet_deuterium' => 1e6,
            'planet_galaxy' => $this->home['galaxy'], 'planet_system' => $this->home['system'], 'planet_planet' => $this->home['planet'],
        ], 'planet_id');

        foreach (['buildings' => 'building', 'defenses' => 'defense', 'ships' => 'ship'] as $table => $prefix) {
            DB::table($table)->insert([$prefix . '_planet_id' => $moon] + ($table === 'buildings' ? ['building_phalanx' => 1] : []));
        }

        DB::table('users')->where('id', $this->player->id)->update(['current_planet' => $moon]);

        return $moon;
    }

    private function deuterium(int $planetId): float
    {
        $value = DB::table('planets')->where('planet_id', $planetId)->value('planet_deuterium');
        $this->assertIsNumeric($value);

        return (float) $value;
    }

    private function homePlanetId(): int
    {
        $id = DB::table('planets')->where('planet_user_id', $this->player->id)->where('planet_type', 1)->value('planet_id');
        $this->assertIsNumeric($id);

        return (int) $id;
    }

    private function asPlayer(): static
    {
        (new ReflectionProperty(Users::class, 'instance'))->setValue(null, null);

        $password = User::findOrFail($this->player->id)->getAuthPassword();

        return $this->actingAs($this->player)->withSession([
            'user_id' => $this->player->id,
            'user_password' => Hash::make($password . '-test-secret'),
        ]);
    }

    private function register(string $name): User
    {
        $user = app(RegistrationService::class)->register(['name' => $name, 'email' => strtolower($name) . '@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }
}
