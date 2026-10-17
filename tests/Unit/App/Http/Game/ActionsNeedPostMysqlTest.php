<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Game;

use App\Http\Controllers\GameController;
use App\Libraries\Users;
use App\Models\Planets;
use App\Models\User;
use App\Services\RegistrationService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\MysqlTestCase;

/**
 * Game actions change something only by POST, so the CSRF check covers them.
 * Before, a plain link (in an alliance text, a message, another site) could
 * build, cancel, research, hire officers, kick members, delete ranks,
 * disband or hand over an alliance, delete messages or send fleets: the
 * controllers read the action from the URL as well.
 */
#[CoversClass(GameController::class)]
class ActionsNeedPostMysqlTest extends MysqlTestCase
{
    private User $founder;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        // the tick's maintenance (MySQL-only SQL) has just run
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);

        $this->founder = $this->player('Founder');
        $this->member = $this->player('Member');

        DB::table('planets')->update(['planet_metal' => 1e7, 'planet_crystal' => 1e7, 'planet_deuterium' => 1e7]);
        DB::table('premium')->update(['premium_dark_matter' => 100000]);
        DB::table('buildings')->update(['building_laboratory' => 1, 'building_hangar' => 2, 'building_robot_factory' => 2]);
        DB::table('ships')->update(['ship_small_cargo_ship' => 10, 'ship_espionage_probe' => 10]);
        DB::table('messages')->insert(['message_receiver' => $this->founder->id, 'message_sender' => 0, 'message_time' => time(), 'message_type' => 4, 'message_from' => 'x', 'message_subject' => 'x', 'message_text' => 'x', 'message_read' => 0]);

        $allianceId = DB::table('alliance')->insertGetId([
            'alliance_name' => 'Testers', 'alliance_tag' => 'TST', 'alliance_owner' => $this->founder->id, 'alliance_register_time' => time(),
            'alliance_ranks' => '[{"rank":"Leader","rights":{"1":1,"2":1,"3":1,"4":1,"5":1,"6":1,"7":1,"8":1,"9":1}},{"rank":"Newcomer","rights":{"1":0,"2":0,"3":0,"4":0,"5":0,"6":0,"7":0,"8":0,"9":1}},{"rank":"Officer","rights":{"1":0,"2":1,"3":0,"4":0,"5":0,"6":0,"7":0,"8":0,"9":1}}]',
        ]);
        DB::table('alliance_statistics')->insert(['alliance_statistic_alliance_id' => $allianceId]);
        DB::table('users')->whereIn('id', [$this->founder->id, $this->member->id])->update(['ally_id' => $allianceId, 'ally_rank_id' => 1]);
        DB::table('users')->where('id', $this->founder->id)->update(['ally_rank_id' => 0]);
        DB::table('buddys')->insert(['buddy_sender' => $this->member->id, 'buddy_receiver' => $this->founder->id, 'buddy_status' => 0, 'buddy_request_text' => '']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function actions(): iterable
    {
        yield 'build' => ['supplies&cmd=insert&building=1'];
        yield 'research' => ['research&cmd=search&tech=113'];
        yield 'defenses' => ['defenses&fmenge[401]=1'];
        yield 'hire officer' => ['premium&offi=601&time=week'];
        yield 'kick member' => ['alliance&mode=admin&edit=members&kick={member}'];
        yield 'change rank' => ['alliance&mode=admin&edit=members&id={member}&newrang=0'];
        yield 'delete rank' => ['alliance&mode=admin&edit=rights&d=2'];
        yield 'add rank' => ['alliance&mode=admin&edit=rights&newrangname=Spy'];
        yield 'disband' => ['alliance&mode=admin&edit=exit'];
        yield 'hand over' => ['alliance&mode=admin&edit=transfer&newleader={member}'];
        yield 'alliance settings' => ['alliance&mode=admin&edit=ally&options=1&web=http://x.example&image=&request_notallow=1'];
        yield 'accept buddy' => ['buddies&mode=1&sm=2&bid=1'];
        yield 'delete buddy' => ['buddies&mode=1&sm=1&bid=1'];
        yield 'delete messages' => ['messages&deletemessages=deleteall'];
        yield 'rename planet' => ['planetlayer&planetName=Pwned'];
        yield 'send probes' => ['galaxy&fleet=true&action=send&order=6&galaxy={g}&system={s}&planet={p}&planettype=1&shipcount=1'];
        yield 'send fleet' => ['fleet4&ship202=1&galaxy=1&system=1&planet=2&planettype=1&mission=3&speed=10&resource1=1'];
    }

    #[DataProvider('actions')]
    public function testAGetRequestChangesNothing(string $action): void
    {
        $before = $this->snapshot();

        $target = DB::table('planets')->where('planet_user_id', $this->member->id)->first(['planet_galaxy', 'planet_system', 'planet_planet']);
        $this->assertNotNull($target);

        $response = $this->asFounder()->get('/game.php?page=' . strtr($action, [
            '{member}' => (string) $this->member->id,
            '{g}' => (string) $target->planet_galaxy,
            '{s}' => (string) $target->planet_system,
            '{p}' => (string) $target->planet_planet,
        ]));

        // the page was really handled (not a crash or the login); viewing
        // the messages marks them read, which is fine
        $this->assertContains($response->getStatusCode(), [200, 302]);
        $this->assertStringNotContainsString('login', (string) $response->headers->get('location'));

        $this->assertSame($before, $this->snapshot());
    }

    public function testThePostOfALinkStillWorks(): void
    {
        $this->asFounder()->post('/game.php?page=alliance&mode=admin&edit=members&kick=' . $this->member->id);

        $this->assertSame(0, User::findOrFail($this->member->id)->ally_id);
    }

    /**
     * The phalanx scan costs deuterium: a plain link to it spends nothing,
     * the POST the galaxy view sends does.
     */
    public function testOnlyThePostOfAPhalanxScanCostsDeuterium(): void
    {
        $home = DB::table('planets')->where('planet_user_id', $this->founder->id)->first(['planet_galaxy', 'planet_system', 'planet_planet']);
        $this->assertNotNull($home);
        $moon = DB::table('planets')->insertGetId([
            'planet_user_id' => $this->founder->id, 'planet_name' => 'Moon', 'planet_type' => 3, 'planet_deuterium' => 50000,
            'planet_galaxy' => $home->planet_galaxy, 'planet_system' => $home->planet_system, 'planet_planet' => $home->planet_planet,
        ], 'planet_id');
        foreach (['buildings' => 'building', 'defenses' => 'defense', 'ships' => 'ship'] as $table => $prefix) {
            DB::table($table)->insert([$prefix . '_planet_id' => $moon] + ($table === 'buildings' ? ['building_phalanx' => 1] : []));
        }
        DB::table('users')->where('id', $this->founder->id)->update(['current_planet' => $moon]);

        $url = '/game.php?page=phalanx&galaxy=' . $home->planet_galaxy . '&system=' . $home->planet_system . '&planet=' . $home->planet_planet . '&planettype=1';

        $deuterium = fn (): float => (float) Planets::findOrFail($moon)->planet_deuterium;

        $this->asFounder()->get($url);
        $this->assertSame(50000.0, $deuterium());

        $this->asFounder()->post($url);
        $this->assertSame(40000.0, $deuterium());
    }

    /**
     * Logged in as the game does it (the session login data the player
     * library reads), with that library built afresh for the request.
     */
    private function asFounder(): static
    {
        (new ReflectionProperty(Users::class, 'instance'))->setValue(null, null);

        $password = User::findOrFail($this->founder->id)->getAuthPassword();

        return $this->actingAs($this->founder)->withSession([
            'user_id' => $this->founder->id,
            'user_password' => Hash::make($password . '-test-secret'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $tables = ['users', 'planets', 'buildings', 'research', 'research_queues', 'building_queues', 'premium', 'alliance', 'buddys', 'messages', 'fleets', 'ships'];
        $snapshot = [];

        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn (object $row): array => array_diff_key((array) $row, array_flip(['onlinetime', 'current_page', 'planet_last_update', 'updated_at', 'lastip', 'agent', 'message_read'])))->all();
            $snapshot[$table] = (string) json_encode($rows);
        }

        return $snapshot;
    }

    private function player(string $name): User
    {
        $user = app(RegistrationService::class)->register([
            'name' => $name,
            'email' => strtolower($name) . '@example.com',
            'password' => 'secret-password',
        ]);
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }
}
