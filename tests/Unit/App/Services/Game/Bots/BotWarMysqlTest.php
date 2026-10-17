<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Bots;

use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\MissionsEnumerator as Missions;
use App\Core\Enumerators\PlanetTypesEnumerator as PlanetTypes;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Core\GameObjects\GameObjectRegistry;
use App\Libraries\MissionControlLib;
use App\Libraries\PlanetLib;
use App\Models\User;
use App\Services\Admin\BotService;
use App\Services\Game\Bots\BotFleetPlanner;
use App\Services\Game\Bots\BotPlayerService;
use App\Services\Game\Bots\BotStrategy;
use App\Services\Game\SystemEspionageService;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Testing\Fakes\ExceptionHandlerFake;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Bots at war on a real database: warships take on a defended planet and
 * leave debris, recyclers bring it in, a moon is supplied from home and
 * built up, and death stars fly against an enemy moon.
 */
#[CoversClass(BotFleetPlanner::class)]
#[CoversClass(BotStrategy::class)]
#[CoversClass(BotPlayerService::class)]
class BotWarMysqlTest extends MysqlTestCase
{
    private int $bot;

    private int $planet;

    private int $other;

    private int $otherPlanet;

    /** @var array<string, int> */
    private array $home;

    protected function setUp(): void
    {
        parent::setUp();

        config(['version.files' => '4.0.0']);
        // a bot turn reports its errors instead of failing: none may occur
        Exceptions::fake();
        $this->seed(OptionsTableSeeder::class);

        $this->assertSame(2, app(BotService::class)->createBots(2, 1, 1));

        [$this->bot, $this->other] = User::where('user_is_bot', true)->orderBy('id')->pluck('id')->map(self::int(...))->all();
        $this->planet = User::findOrFail($this->bot)->home_planet_id;
        $this->otherPlanet = User::findOrFail($this->other)->home_planet_id;

        $this->home = $this->planetRow($this->planet);
        DB::table('planets')->where('planet_id', $this->otherPlanet)->update([
            'planet_system' => $this->home['planet_system'],
            'planet_planet' => $this->home['planet_planet'] === 15 ? 14 : 15,
        ]);
        DB::table('planets')->whereIn('planet_id', [$this->planet, $this->otherPlanet])->update([
            'planet_metal' => 0, 'planet_crystal' => 0, 'planet_deuterium' => 50000, 'planet_last_update' => time(),
        ]);
        DB::table('research')->where('research_user_id', $this->bot)->update([
            'research_computer_technology' => 5, 'research_combustion_drive' => 6, 'research_impulse_drive' => 4, 'research_hyperspace_drive' => 7,
        ]);
    }

    protected function tearDown(): void
    {
        // skipped without MariaDB: nothing was faked
        if (Exceptions::getFacadeRoot() instanceof ExceptionHandlerFake) {
            Exceptions::assertNothingReported();
        }

        parent::tearDown();
    }

    public function testWarshipsTakeOnADefendedPlanetAndLeaveDebris(): void
    {
        $this->ships($this->planet, [Ships::SHIP_CRUISER => 40, Ships::SHIP_SMALL_CARGO_SHIP => 10]);
        DB::table('planets')->where('planet_id', $this->otherPlanet)->update(['planet_metal' => 60000, 'planet_crystal' => 30000]);
        DB::table('defenses')->where('defense_planet_id', $this->otherPlanet)->update(['defense_rocket_launcher' => 20]);
        app(SystemEspionageService::class)->record($this->bot, $this->otherPlanet, time() - 60);

        app(BotPlayerService::class)->play($this->bot, time());

        $attack = $this->fleet(Missions::ATTACK);
        $this->assertGreaterThan(10, $attack['fleet_amount'], 'the cruisers fly with the cargo ships');

        DB::table('fleets')->where('fleet_id', $attack['fleet_id'])->update(['fleet_start_time' => time() - 1]);
        (new MissionControlLib())->arrivingFleets();

        $target = $this->planetRow($this->otherPlanet);
        // most rocket launchers fell (some are repaired after the battle)
        $this->assertLessThan(15, self::int(DB::table('defenses')->where('defense_planet_id', $this->otherPlanet)->value('defense_rocket_launcher')));
        // 30 % of the destroyed rocket launchers' metal
        $this->assertGreaterThan(0, $target['planet_debris_metal']);
    }

    public function testWithoutCargoShipsTheWarshipsStillFight(): void
    {
        $this->ships($this->planet, [Ships::SHIP_CRUISER => 40]);
        DB::table('defenses')->where('defense_planet_id', $this->otherPlanet)->update(['defense_rocket_launcher' => 20]);
        app(SystemEspionageService::class)->record($this->bot, $this->otherPlanet, time() - 60);

        app(BotPlayerService::class)->play($this->bot, time());

        $this->assertSame(40, $this->fleet(Missions::ATTACK)['fleet_amount']);
    }

    public function testRecyclersBringInTheDebris(): void
    {
        $this->ships($this->planet, [Ships::SHIP_RECYCLER => 5]);
        DB::table('planets')->where('planet_id', $this->otherPlanet)->update(['planet_debris_metal' => 25000, 'planet_debris_crystal' => 5000]);

        app(BotPlayerService::class)->play($this->bot, time());

        $fleet = $this->fleet(Missions::RECYCLE);
        $this->assertSame(PlanetTypes::DEBRIS, $fleet['fleet_end_type']);
        // 30,000 of debris, 20,000 per recycler
        $this->assertSame(2, $fleet['fleet_amount']);

        // one fleet per field: the next turn does not send another
        app(BotPlayerService::class)->play($this->bot, time());
        $this->assertSame(1, DB::table('fleets')->where('fleet_mission', Missions::RECYCLE)->count());
    }

    public function testAMoonIsSuppliedFromHomeAndBuiltUp(): void
    {
        $this->assertTrue((new PlanetLib())->setNewMoon($this->home['planet_galaxy'], $this->home['planet_system'], $this->home['planet_planet'], $this->bot, 'Moon', 0, 5000, 1));
        $moon = self::int(DB::table('planets')->where('planet_user_id', $this->bot)->where('planet_type', PlanetTypes::MOON)->value('planet_id'));
        $this->ships($this->planet, [Ships::SHIP_SMALL_CARGO_SHIP => 20]);
        DB::table('planets')->where('planet_id', $this->planet)->update(['planet_metal' => 100000, 'planet_crystal' => 100000]);

        app(BotPlayerService::class)->play($this->bot, time());

        // what the lunar base costs
        $transport = $this->fleet(Missions::TRANSPORT);
        $this->assertSame(PlanetTypes::MOON, $transport['fleet_end_type']);
        $this->assertSame([20000, 40000, 20000], [$transport['fleet_resource_metal'], $transport['fleet_resource_crystal'], $transport['fleet_resource_deuterium']]);

        // delivered: the moon builds its lunar base
        DB::table('fleets')->where('fleet_id', $transport['fleet_id'])->update(['fleet_start_time' => time() - 1]);
        (new MissionControlLib())->arrivingFleets();
        app(BotPlayerService::class)->play($this->bot, time());

        $this->assertEquals(Buildings::BUILDING_MONDBASIS, DB::table('building_queues')->where('planet_id', $moon)->value('building_id'));
    }

    public function testDeathStarsFlyAgainstAnEnemyMoon(): void
    {
        $other = $this->planetRow($this->otherPlanet);
        $this->assertTrue((new PlanetLib())->setNewMoon($other['planet_galaxy'], $other['planet_system'], $other['planet_planet'], $this->other, 'Moon', 0, 5000, 1));
        $this->ships($this->planet, [Ships::SHIP_DEATHSTAR => 2]);

        app(BotPlayerService::class)->play($this->bot, time());

        $fleet = $this->fleet(Missions::DESTROY);
        $this->assertSame(PlanetTypes::MOON, $fleet['fleet_end_type']);
        $this->assertSame($other['planet_planet'], $fleet['fleet_end_planet']);
        $this->assertSame($this->other, $fleet['fleet_target_owner']);
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
    private function fleet(int $mission): array
    {
        $fleet = DB::table('fleets')->where('fleet_owner', $this->bot)->where('fleet_mission', $mission)->first();
        $this->assertNotNull($fleet, 'no fleet with mission ' . $mission);

        return array_map(self::int(...), array_filter(get_object_vars($fleet), is_numeric(...)));
    }

    /**
     * @return array<string, int>
     */
    private function planetRow(int $planetId): array
    {
        $planet = DB::table('planets')->where('planet_id', $planetId)->first();
        $this->assertNotNull($planet);

        return array_map(self::int(...), array_filter(get_object_vars($planet), is_numeric(...)));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
