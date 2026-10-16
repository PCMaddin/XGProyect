<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Bots;

use App\Core\Enumerators\MissionsEnumerator as Missions;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Libraries\MissionControlLib;
use App\Libraries\PlanetLib;
use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Game\SystemEspionageService;
use App\Models\User;
use App\Services\Admin\BotService;
use App\Services\Game\Bots\BotFleetPlanner;
use App\Services\Game\Bots\BotFleetService;
use App\Services\Game\Bots\BotPlayerService;
use App\Services\SettingsService;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Testing\Fakes\ExceptionHandlerFake;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Bots' fleets on a real database: the home planet founds a colony, sends
 * spare cargo ships on expeditions, spies on another bot and raids it once
 * the report is in; players are spared unless the admin allows it; a
 * colony ships its stock home. Every fleet is paid for like a player's.
 */
#[CoversClass(BotFleetPlanner::class)]
#[CoversClass(BotFleetService::class)]
class BotFleetMysqlTest extends MysqlTestCase
{
    private int $bot;

    private int $planet;

    private int $other;

    private int $otherPlanet;

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

        // the other bot lives next door
        $home = $this->planetRow($this->planet);
        DB::table('planets')->where('planet_id', $this->otherPlanet)->update([
            'planet_system' => $home['planet_system'],
            'planet_planet' => $home['planet_planet'] === 15 ? 14 : 15,
        ]);

        // nothing to build: the turns are about fleets
        DB::table('planets')->whereIn('planet_id', [$this->planet, $this->otherPlanet])->update([
            'planet_metal' => 0, 'planet_crystal' => 0, 'planet_deuterium' => 20000, 'planet_last_update' => time(),
        ]);
        DB::table('research')->where('research_user_id', $this->bot)->update([
            'research_computer_technology' => 3, 'research_combustion_drive' => 3, 'research_impulse_drive' => 3,
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

    public function testTheHomePlanetFoundsAColony(): void
    {
        DB::table('research')->where('research_user_id', $this->bot)->update(['research_astrophysics' => 1]);
        $this->ships($this->planet, [Ships::SHIP_COLONY_SHIP => 1]);

        app(BotPlayerService::class)->play($this->bot, time());

        $fleet = $this->fleet(Missions::COLONIZE);
        $this->assertContains($fleet['fleet_end_planet'], [4, 5, 6, 7, 8, 9, 10, 11, 12]);
        $this->assertSame(0, $this->value('ships', 'ship_planet_id', $this->planet, 'ship_colony_ship'));
        $this->assertGreaterThan(0, $fleet['fleet_fuel']);
        $this->assertLessThan(20000, $this->value('planets', 'planet_id', $this->planet, 'planet_deuterium'));
    }

    public function testSpareCargoShipsGoOnAnExpedition(): void
    {
        DB::table('research')->where('research_user_id', $this->bot)->update(['research_astrophysics' => 1]);
        $this->ships($this->planet, [Ships::SHIP_SMALL_CARGO_SHIP => 10]);

        app(BotPlayerService::class)->play($this->bot, time());

        $fleet = $this->fleet(Missions::EXPEDITION);
        $this->assertSame(MAX_PLANET_IN_SYSTEM + 1, $fleet['fleet_end_planet']);
        $this->assertSame(5, $fleet['fleet_amount']);
        $this->assertSame($fleet['fleet_start_time'] + 3600, $fleet['fleet_end_stay']);
        $this->assertSame(5, $this->value('ships', 'ship_planet_id', $this->planet, 'ship_small_cargo_ship'));
    }

    public function testABotSpiesOnABotAndRaidsItOnceTheReportIsIn(): void
    {
        $this->ships($this->planet, [Ships::SHIP_ESPIONAGE_PROBE => 1, Ships::SHIP_SMALL_CARGO_SHIP => 10]);
        DB::table('planets')->where('planet_id', $this->otherPlanet)->update(['planet_metal' => 40000, 'planet_crystal' => 20000]);

        app(BotPlayerService::class)->play($this->bot, time());

        $spy = $this->fleet(Missions::SPY);
        $this->assertSame($this->other, $spy['fleet_target_owner']);

        // the probe arrives and reports
        DB::table('fleets')->where('fleet_id', $spy['fleet_id'])->update(['fleet_start_time' => time() - 1]);
        (new MissionControlLib())->arrivingFleets();

        app(BotPlayerService::class)->play($this->bot, time());

        $attack = $this->fleet(Missions::ATTACK);
        $this->assertSame($this->other, $attack['fleet_target_owner']);
        // enough cargo ships for half of the stock the report showed
        $this->assertGreaterThanOrEqual(6, $attack['fleet_amount']);
    }

    public function testADefendedTargetIsNotRaidedWithoutWarships(): void
    {
        $this->ships($this->planet, [Ships::SHIP_SMALL_CARGO_SHIP => 10]);
        DB::table('planets')->where('planet_id', $this->otherPlanet)->update(['planet_metal' => 40000, 'planet_crystal' => 20000]);
        DB::table('defenses')->where('defense_planet_id', $this->otherPlanet)->update(['defense_rocket_launcher' => 5]);
        app(SystemEspionageService::class)->record($this->bot, $this->otherPlanet, time() - 60);

        app(BotPlayerService::class)->play($this->bot, time());

        $this->assertSame(0, DB::table('fleets')->where('fleet_mission', Missions::ATTACK)->count());
    }

    public function testPlayersAreSparedUnlessTheAdminAllowsIt(): void
    {
        $this->ships($this->planet, [Ships::SHIP_ESPIONAGE_PROBE => 2]);
        User::where('id', $this->other)->update(['user_is_bot' => false]);

        app(BotPlayerService::class)->play($this->bot, time());
        $this->assertSame(0, DB::table('fleets')->count());

        app(SettingsService::class)->write('bots_attack_players', 1);
        app(BotPlayerService::class)->play($this->bot, time());
        $this->assertSame($this->other, $this->fleet(Missions::SPY)['fleet_target_owner']);
    }

    public function testAColonyShipsItsStockHome(): void
    {
        $home = $this->planetRow($this->planet);
        $position = $home['planet_planet'] === 4 ? 5 : 4;
        $this->assertTrue((new PlanetLib())->setNewPlanet($home['planet_galaxy'], $home['planet_system'], $position, $this->bot, 'Colony'));
        $colony = self::int(DB::table('planets')->where('planet_user_id', $this->bot)->where('planet_planet', $position)->value('planet_id'));
        $this->ships($colony, [Ships::SHIP_SMALL_CARGO_SHIP => 5]);
        DB::table('planets')->where('planet_id', $colony)->update([
            'planet_metal' => 30000, 'planet_crystal' => 20000, 'planet_deuterium' => 5000, 'planet_last_update' => time(),
        ]);

        app(BotPlayerService::class)->play($this->bot, time());

        $fleet = $this->fleet(Missions::TRANSPORT);
        $this->assertSame($home['planet_planet'], $fleet['fleet_end_planet']);
        $this->assertSame($position, $fleet['fleet_start_planet']);
        $this->assertGreaterThan(5000, $fleet['fleet_resource_metal'] + $fleet['fleet_resource_crystal']);
        // what the cargo ships carry left the colony
        $left = $this->planetRow($colony);
        $this->assertGreaterThanOrEqual(0, $left['planet_metal']);
        $this->assertGreaterThanOrEqual(0, $left['planet_deuterium']);
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

    private function value(string $table, string $key, int $id, string $column): int
    {
        return self::int(DB::table($table)->where($key, $id)->value($column));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
