<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Bots;

use App\Core\Enumerators\DefensesEnumerator as Defenses;
use App\Core\Enumerators\MissionsEnumerator as Missions;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Core\GameObjects\GameObjectRegistry;
use App\Libraries\MissionControlLib;
use App\Models\User;
use App\Services\Admin\BotService;
use App\Services\Game\AllianceService;
use App\Services\Game\Bots\BotAlliance;
use App\Services\Game\Bots\BotCoordinator;
use App\Services\Game\Bots\BotFleetService;
use App\Services\Game\Bots\BotMissiles;
use App\Services\Game\Bots\BotPlayerService;
use App\Services\Game\MissileService;
use App\Services\Game\SystemEspionageService;
use App\Support\FleetShips;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Testing\Fakes\ExceptionHandlerFake;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Bots together, on a real database: neighbours found and join an alliance
 * of bots only; a bot holds at an ally's planet an enemy attacks and fights
 * in the battle; two allies fly a synced ACS attack that fights as one; a
 * bot fires missiles that destroy defences. Never at an ally, never with a
 * player.
 */
#[CoversClass(BotAlliance::class)]
#[CoversClass(BotCoordinator::class)]
#[CoversClass(BotMissiles::class)]
#[CoversClass(BotFleetService::class)]
#[CoversClass(MissileService::class)]
class BotCoordinatorMysqlTest extends MysqlTestCase
{
    /** @var list<int> */
    private array $bots;

    protected function setUp(): void
    {
        parent::setUp();

        config(['version.files' => '4.0.0']);
        // a bot turn reports its errors instead of failing: none may occur
        Exceptions::fake();
        $this->seed(OptionsTableSeeder::class);

        $this->assertSame(4, app(BotService::class)->createBots(4, 1, 1));
        $this->bots = array_values(User::where('user_is_bot', true)->orderBy('id')->pluck('id')->map(self::int(...))->all());

        // far apart unless a test moves them; nothing to build: the turns are about the alliance and fleets
        foreach ($this->bots as $index => $bot) {
            $this->place($bot, 250 + 60 * $index, 8);
            DB::table('planets')->where('planet_id', $this->home($bot))->update([
                'planet_metal' => 0, 'planet_crystal' => 0, 'planet_deuterium' => 50000, 'planet_last_update' => time(),
            ]);
            DB::table('research')->where('research_user_id', $bot)->update([
                'research_computer_technology' => 5, 'research_combustion_drive' => 4, 'research_impulse_drive' => 4,
            ]);
        }
    }

    protected function tearDown(): void
    {
        // skipped without MariaDB: nothing was faked
        if (Exceptions::getFacadeRoot() instanceof ExceptionHandlerFake) {
            Exceptions::assertNothingReported();
        }

        parent::tearDown();
    }

    public function testNeighboursFoundAndJoinAnAllianceOfBots(): void
    {
        [$founder, $neighbour, $far, $player] = $this->bots;
        $this->place($founder, 100, 4);
        $this->place($neighbour, 120, 4);
        $this->place($far, 400, 4);
        $this->place($player, 110, 4);
        User::where('id', $player)->update(['user_is_bot' => false]);

        app(BotPlayerService::class)->play($founder, time());
        $allyId = self::int(User::findOrFail($founder)->ally_id);
        $this->assertNotSame(0, $allyId);
        $this->assertSame('B1-100', DB::table('alliance')->where('alliance_id', $allyId)->value('alliance_tag'));

        // the neighbour applies, a player does so on the alliance page
        app(BotPlayerService::class)->play($neighbour, time());
        $this->assertSame($allyId, self::int(User::findOrFail($neighbour)->ally_request));
        app(AllianceService::class)->apply($player, $allyId, 'Me too', time());

        // the founder accepts the bot and declines the player
        app(BotPlayerService::class)->play($founder, time());
        $this->assertSame($allyId, self::int(User::findOrFail($neighbour)->ally_id));
        $this->assertSame(0, self::int(User::findOrFail($player)->ally_id));
        $this->assertSame(0, self::int(User::findOrFail($player)->ally_request));

        // nobody lives near the far bot: it stays alone
        app(BotPlayerService::class)->play($far, time());
        $this->assertSame(0, self::int(User::findOrFail($far)->ally_id));
        $this->assertSame(0, self::int(User::findOrFail($far)->ally_request));
        $this->assertSame(1, DB::table('alliance')->count());
    }

    public function testBotsNeverJoinAPlayersAlliance(): void
    {
        [$bot, $other, $third, $player] = $this->bots;
        $this->place($bot, 100, 4);
        $this->place($other, 300, 4);
        $this->place($third, 450, 4);
        $this->place($player, 105, 4);
        User::where('id', $player)->update(['user_is_bot' => false]);
        app(AllianceService::class)->openForApplications(app(AllianceService::class)->found($player, 'Players', 'PLY', time()));

        app(BotPlayerService::class)->play($bot, time());

        $this->assertSame(0, self::int(User::findOrFail($bot)->ally_request));
        $this->assertSame(0, self::int(User::findOrFail($bot)->ally_id));
    }

    public function testTheFounderChoosesTheWarriorClassOnceItIsFree(): void
    {
        [$founder, $neighbour] = $this->bots;
        $this->place($founder, 100, 4);
        $this->place($neighbour, 101, 4);
        app(BotPlayerService::class)->play($founder, time());
        $allyId = self::int(User::findOrFail($founder)->ally_id);

        DB::table('alliance')->where('alliance_id', $allyId)->update(['alliance_register_time' => time() - 15 * 86400]);
        app(BotPlayerService::class)->play($founder, time());

        $this->assertSame(3, self::int(DB::table('alliance')->where('alliance_id', $allyId)->value('alliance_class')));
    }

    public function testABotHoldsAtAThreatenedAllyAndFightsInTheBattle(): void
    {
        [$helper, $ally, $enemy] = $this->bots;
        $this->place($helper, 100, 4);
        $this->place($ally, 100, 8);
        $this->place($enemy, 140, 8);
        $this->ally([$helper, $ally]);
        $this->ships($this->home($helper), [Ships::SHIP_LIGHT_FIGHTER => 40]);

        // an enemy attack arrives at the ally in two hours
        $attackId = $this->enemyFleet($enemy, $ally, [Ships::SHIP_LIGHT_FIGHTER => 10], time() + 7200);

        app(BotPlayerService::class)->play($helper, time());

        $stay = $this->fleet($helper, Missions::STAY);
        $this->assertSame(40, $stay['fleet_amount']);
        $this->assertSame(8, $stay['fleet_end_planet']);
        $this->assertLessThan(time() + 7200, $stay['fleet_start_time']);
        $this->assertGreaterThanOrEqual(time() + 7200 + 3600, $stay['fleet_end_stay']);

        // the defenders have arrived, the attack comes in
        DB::table('fleets')->where('fleet_id', $stay['fleet_id'])->update(['fleet_start_time' => time() - 60]);
        DB::table('fleets')->where('fleet_id', $attackId)->update(['fleet_start_time' => time() - 1]);
        (new MissionControlLib())->arrivingFleets();

        $owners = $this->reportOwners();
        $this->assertContains((string) $helper, $owners);
        $this->assertSame((string) $ally, end($owners));
        // ten light fighters do not survive forty
        $this->assertFalse(DB::table('fleets')->where('fleet_id', $attackId)->exists());
    }

    public function testAlliedBotsFlyASyncedAcsAttack(): void
    {
        [$leader, $member, $target] = $this->bots;
        $this->place($leader, 100, 4);
        $this->place($member, 98, 4);
        $this->place($target, 105, 8);
        $this->ally([$leader, $member]);
        $this->ships($this->home($leader), [Ships::SHIP_LIGHT_FIGHTER => 30]);
        $this->ships($this->home($member), [Ships::SHIP_LIGHT_FIGHTER => 30]);
        // the battle simulator: 30 light fighters lose against 35 rocket launchers, 60 win
        DB::table('defenses')->where('defense_planet_id', $this->home($target))->update(['defense_rocket_launcher' => 35]);
        DB::table('planets')->where('planet_id', $this->home($target))->update(['planet_metal' => 60000, 'planet_crystal' => 30000]);
        app(SystemEspionageService::class)->record($member, $this->home($target), time() - 60);
        // a raid by someone alone an hour ago does not spare the target
        DB::table('reports')->insert(['report_owners' => '99,' . $target, 'report_rid' => 'raid', 'report_content' => '', 'report_destroyed' => 0, 'report_time' => time() - 3600]);

        app(BotPlayerService::class)->play($leader, time());

        $attack = $this->fleet($leader, Missions::ATTACK);
        $group = $attack['fleet_group'];
        $this->assertNotSame(0, $group);
        $this->assertSame($leader, self::int(DB::table('acs')->where('acs_id', $group)->value('acs_owner')));
        $this->assertEqualsCanonicalizing([$leader, $member], DB::table('acs_members')->where('acs_group_id', $group)->pluck('acs_user_id')->map(self::int(...))->all());

        app(BotPlayerService::class)->play($member, time());

        // the member flies farther: the whole group waits for it
        $joined = $this->fleet($member, Missions::ACS);
        $this->assertSame($group, $joined['fleet_group']);
        $leaderNow = $this->fleet($leader, Missions::ATTACK);
        $this->assertSame($joined['fleet_start_time'], $leaderNow['fleet_start_time']);
        $this->assertGreaterThan($attack['fleet_start_time'], $leaderNow['fleet_start_time']);
        $this->assertSame($attack['fleet_end_time'] + $leaderNow['fleet_start_time'] - $attack['fleet_start_time'], $leaderNow['fleet_end_time']);

        // both arrive and fight as one
        DB::table('fleets')->where('fleet_group', $group)->update(['fleet_start_time' => time() - 1]);
        (new MissionControlLib())->arrivingFleets();

        $owners = $this->reportOwners();
        $this->assertSame([(string) $leader, (string) $member, (string) $target], $owners);
        $this->assertLessThan(35, $this->value('defenses', 'defense_planet_id', $this->home($target), 'defense_rocket_launcher'));
        $this->assertFalse(DB::table('acs')->where('acs_id', $group)->exists());
        $this->assertSame(2, DB::table('fleets')->where('fleet_group', $group)->where('fleet_mess', 1)->count());
    }

    public function testATargetHitByAnAcsAttackIsSparedForADay(): void
    {
        [$leader, $member, $target] = $this->bots;
        $this->place($leader, 100, 4);
        $this->place($member, 98, 4);
        $this->place($target, 105, 8);
        $this->ally([$leader, $member]);
        $this->ships($this->home($leader), [Ships::SHIP_LIGHT_FIGHTER => 30]);
        $this->ships($this->home($member), [Ships::SHIP_LIGHT_FIGHTER => 30]);
        DB::table('defenses')->where('defense_planet_id', $this->home($target))->update(['defense_rocket_launcher' => 35]);
        DB::table('planets')->where('planet_id', $this->home($target))->update(['planet_metal' => 60000, 'planet_crystal' => 30000]);
        app(SystemEspionageService::class)->record($member, $this->home($target), time() - 60);
        DB::table('reports')->insert(['report_owners' => '98,99,' . $target, 'report_rid' => 'acs', 'report_content' => '', 'report_destroyed' => 0, 'report_time' => time() - 3600]);

        app(BotPlayerService::class)->play($leader, time());

        $this->assertSame(0, DB::table('fleets')->where('fleet_group', '<>', 0)->count());
    }

    public function testNoAcsAttackOnAnAllyOrAPlayer(): void
    {
        [$leader, $member, $target] = $this->bots;
        $this->place($leader, 100, 4);
        $this->place($member, 98, 4);
        $this->place($target, 105, 8);
        $this->ally([$leader, $member, $target]);
        $this->ships($this->home($leader), [Ships::SHIP_LIGHT_FIGHTER => 30, Ships::SHIP_SMALL_CARGO_SHIP => 20]);
        $this->ships($this->home($member), [Ships::SHIP_LIGHT_FIGHTER => 30]);
        DB::table('defenses')->where('defense_planet_id', $this->home($target))->update(['defense_rocket_launcher' => 15]);
        DB::table('planets')->where('planet_id', $this->home($target))->update(['planet_metal' => 60000, 'planet_crystal' => 30000]);
        app(SystemEspionageService::class)->record($member, $this->home($target), time() - 60);

        app(BotPlayerService::class)->play($leader, time());
        $this->assertSame(0, DB::table('fleets')->whereIn('fleet_mission', [Missions::ATTACK, Missions::ACS])->count());

        // a player outside the alliance is spared as well
        DB::table('users')->where('id', $target)->update(['ally_id' => 0, 'user_is_bot' => false]);
        app(BotPlayerService::class)->play($leader, time());
        $this->assertSame(0, DB::table('fleets')->whereIn('fleet_mission', [Missions::ATTACK, Missions::ACS])->count());
        $this->assertSame(0, DB::table('acs')->count());
    }

    public function testABotFiresMissilesThatDestroyDefences(): void
    {
        [$bot, $target, $cheap] = $this->bots;
        $this->place($bot, 100, 4);
        $this->place($target, 110, 8);
        $this->place($cheap, 95, 8);
        DB::table('buildings')->where('building_planet_id', $this->home($bot))->update(['building_missile_silo' => 4, 'building_hangar' => 1]);
        DB::table('defenses')->where('defense_planet_id', $this->home($bot))->update(['defense_interplanetary_missile' => 5]);
        DB::table('defenses')->where('defense_planet_id', $this->home($target))->update(['defense_rocket_launcher' => 50, 'defense_anti-ballistic_missile' => 1]);
        // two rocket launchers are not worth a missile
        DB::table('defenses')->where('defense_planet_id', $this->home($cheap))->update(['defense_rocket_launcher' => 2]);
        foreach ([$target, $cheap] as $spied) {
            app(SystemEspionageService::class)->record($bot, $this->home($spied), time() - 60);
        }

        $now = time();
        app(BotPlayerService::class)->play($bot, $now);

        $missiles = $this->fleet($bot, Missions::MISSILE);
        $this->assertSame(110, $missiles['fleet_end_system']);
        // one to be intercepted, one for 50 rocket launchers (hull 200 each, a missile hits 12,000 and more)
        $this->assertSame(2, $missiles['fleet_amount']);
        $this->assertSame(3, $this->value('defenses', 'defense_planet_id', $this->home($bot), 'defense_interplanetary_missile'));
        $this->assertSame((30 + 60 * 10), $missiles['fleet_start_time'] - $now);

        DB::table('fleets')->where('fleet_id', $missiles['fleet_id'])->update(['fleet_start_time' => time() - 1]);
        (new MissionControlLib())->arrivingFleets();

        $this->assertSame(0, $this->value('defenses', 'defense_planet_id', $this->home($target), 'defense_rocket_launcher'));
        $this->assertSame(0, $this->value('defenses', 'defense_planet_id', $this->home($target), 'defense_anti-ballistic_missile'));
        $this->assertSame(2, $this->value('defenses', 'defense_planet_id', $this->home($cheap), 'defense_rocket_launcher'));
    }

    public function testMissilesStayHomeOutOfRangeAndForAllies(): void
    {
        [$bot, $far, $ally] = $this->bots;
        $this->place($bot, 100, 4);
        // impulse drive 4 reaches 19 systems
        $this->place($far, 120, 8);
        $this->place($ally, 105, 8);
        $this->ally([$bot, $ally]);
        DB::table('buildings')->where('building_planet_id', $this->home($bot))->update(['building_missile_silo' => 4]);
        DB::table('defenses')->where('defense_planet_id', $this->home($bot))->update(['defense_interplanetary_missile' => 5]);
        DB::table('defenses')->whereIn('defense_planet_id', [$this->home($far), $this->home($ally)])->update(['defense_rocket_launcher' => 50]);
        foreach ([$far, $ally] as $spied) {
            app(SystemEspionageService::class)->record($bot, $this->home($spied), time() - 60);
        }

        app(BotPlayerService::class)->play($bot, time());

        $this->assertSame(0, DB::table('fleets')->where('fleet_mission', Missions::MISSILE)->count());
    }

    public function testTheSiloGetsMissilesWhileATargetIsInRange(): void
    {
        [$bot, $target] = $this->bots;
        // impulse drive 1 reaches 4 systems
        $this->place($target, 253, 8);
        DB::table('buildings')->where('building_planet_id', $this->home($bot))->update([
            'building_missile_silo' => 4, 'building_hangar' => 2, 'building_metal_mine' => 1, 'building_solar_plant' => 1,
        ]);
        DB::table('research')->where('research_user_id', $bot)->update(['research_impulse_drive' => 1]);
        DB::table('planets')->where('planet_id', $this->home($bot))->update(['planet_metal' => 400000, 'planet_crystal' => 100000, 'planet_deuterium' => 300000]);

        // without a probe to aim with, the shipyard builds as usual
        app(BotPlayerService::class)->play($bot, time());
        $hangar = DB::table('planets')->where('planet_id', $this->home($bot))->value('planet_b_hangar_id');
        $this->assertIsString($hangar);
        $this->assertStringStartsNotWith(Defenses::DEFENSE_INTERPLANETARY_MISSILE . ',', $hangar);

        DB::table('planets')->where('planet_id', $this->home($bot))->update(['planet_b_hangar_id' => '']);
        // one probe spies on the neighbour, one stays
        $this->ships($this->home($bot), [Ships::SHIP_ESPIONAGE_PROBE => 2]);
        app(BotPlayerService::class)->play($bot, time());

        $hangar = DB::table('planets')->where('planet_id', $this->home($bot))->value('planet_b_hangar_id');
        $this->assertIsString($hangar);
        // the missiles first, then what BotShipyard orders
        $this->assertMatchesRegularExpression('/^' . Defenses::DEFENSE_INTERPLANETARY_MISSILE . ',\d+;/', $hangar);
        $this->assertLessThanOrEqual(8, (int) explode(',', rtrim($hangar, ';'))[1]);
    }

    public function testABotSavesForItsMissileSiloWithATargetInRange(): void
    {
        [$bot, $target] = $this->bots;
        DB::table('buildings')->where('building_planet_id', $this->home($bot))->update([
            'building_metal_mine' => 18, 'building_crystal_mine' => 16, 'building_deuterium_sintetizer' => 14, 'building_solar_plant' => 22,
            'building_robot_factory' => 6, 'building_hangar' => 6, 'building_laboratory' => 8, 'building_missile_silo' => 3,
            'building_metal_store' => 6, 'building_crystal_store' => 6, 'building_deuterium_tank' => 6,
        ]);
        DB::table('planets')->where('planet_id', $this->home($bot))->update(['planet_metal' => 100000, 'planet_crystal' => 100000, 'planet_deuterium' => 100000]);

        // nobody within missile range: it builds as usual
        app(BotPlayerService::class)->play($bot, time());
        $this->assertNotSame(44, self::int(DB::table('building_queues')->where('planet_id', $this->home($bot))->value('building_id')));
        DB::table('building_queues')->delete();
        DB::table('research_queues')->delete();
        DB::table('planets')->where('planet_id', $this->home($bot))->update([
            'planet_metal' => 100000, 'planet_crystal' => 100000, 'planet_deuterium' => 100000, 'planet_b_hangar_id' => '',
        ]);

        // another bot 10 systems away
        $this->place($target, 260, 8);
        app(BotPlayerService::class)->play($bot, time());

        // level 4 costs 160,000 metal and crystal: no mine, no research, no ships meanwhile
        $this->assertSame(0, DB::table('building_queues')->where('planet_id', $this->home($bot))->count());
        $this->assertSame(0, DB::table('research_queues')->where('user_id', $bot)->count());
        $this->assertContains(DB::table('planets')->where('planet_id', $this->home($bot))->value('planet_b_hangar_id'), ['', '0', null]);

        DB::table('planets')->where('planet_id', $this->home($bot))->update(['planet_metal' => 200000, 'planet_crystal' => 200000]);
        app(BotPlayerService::class)->play($bot, time());
        $this->assertSame(44, self::int(DB::table('building_queues')->where('planet_id', $this->home($bot))->value('building_id')));
    }

    /**
     * The bots form an alliance founded by the first.
     *
     * @param list<int> $members
     */
    private function ally(array $members): void
    {
        $allyId = app(AllianceService::class)->found($members[0], 'Bots', 'BOTS', time());
        app(AllianceService::class)->openForApplications($allyId);
        User::whereIn('id', $members)->update(['ally_id' => $allyId]);
    }

    /**
     * @return list<string>
     */
    private function reportOwners(): array
    {
        $owners = DB::table('reports')->value('report_owners');
        $this->assertIsString($owners);

        return explode(',', $owners);
    }

    private function place(int $bot, int $system, int $position): void
    {
        DB::table('planets')->where('planet_id', $this->home($bot))->update(['planet_galaxy' => 1, 'planet_system' => $system, 'planet_planet' => $position]);
        User::where('id', $bot)->update(['galaxy' => 1, 'system' => $system, 'planet' => $position]);
    }

    /**
     * @param array<int, int> $ships
     */
    private function enemyFleet(int $owner, int $target, array $ships, int $arrival): int
    {
        $from = DB::table('planets')->where('planet_id', $this->home($owner))->first();
        $to = DB::table('planets')->where('planet_id', $this->home($target))->first();
        $this->assertNotNull($from);
        $this->assertNotNull($to);

        return DB::table('fleets')->insertGetId([
            'fleet_owner' => $owner, 'fleet_mission' => Missions::ATTACK, 'fleet_amount' => array_sum($ships), 'fleet_array' => FleetShips::encode($ships),
            'fleet_start_time' => $arrival, 'fleet_start_galaxy' => $from->planet_galaxy, 'fleet_start_system' => $from->planet_system,
            'fleet_start_planet' => $from->planet_planet, 'fleet_start_type' => 1, 'fleet_end_time' => $arrival + 3600, 'fleet_end_stay' => 0,
            'fleet_end_galaxy' => $to->planet_galaxy, 'fleet_end_system' => $to->planet_system, 'fleet_end_planet' => $to->planet_planet,
            'fleet_end_type' => 1, 'fleet_target_owner' => $target, 'fleet_mess' => 0, 'fleet_group' => 0, 'fleet_creation' => time(),
        ]);
    }

    /**
     * @param array<int, int> $ships
     */
    private function ships(int $planetId, array $ships): void
    {
        $columns = [];

        foreach ($ships as $ship => $amount) {
            $columns[app(GameObjectRegistry::class)->names()[$ship]] = $amount;
        }

        DB::table('ships')->where('ship_planet_id', $planetId)->update($columns);
    }

    /**
     * The bot's fleet on this mission, its numbers as int.
     *
     * @return array<string, int>
     */
    private function fleet(int $owner, int $mission): array
    {
        $fleet = DB::table('fleets')->where('fleet_owner', $owner)->where('fleet_mission', $mission)->first();
        $this->assertNotNull($fleet, 'no fleet with mission ' . $mission);

        return array_map(self::int(...), array_filter(get_object_vars($fleet), is_numeric(...)));
    }

    private function home(int $bot): int
    {
        return self::int(User::findOrFail($bot)->home_planet_id);
    }

    private function value(string $table, string $key, int $id, string $column): int
    {
        return self::int(DB::table($table)->where($key, $id)->value($column));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
