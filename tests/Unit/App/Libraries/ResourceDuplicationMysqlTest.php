<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Exceptions\NoLongerOnPlanet;
use App\Libraries\MissionControlLib;
use App\Libraries\UpdatesLibrary;
use App\Models\BuildingQueue;
use App\Models\Planets;
use App\Models\ResearchQueue;
use App\Models\User;
use App\Services\Game\BuildingQueueService;
use App\Services\Game\PlanetStock;
use App\Services\Game\ResearchQueueService;
use App\Services\RegistrationService;
use App\Support\FleetShips;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\MysqlTestCase;

/**
 * Every page view of every player runs the game's updates, so two views at
 * the same moment see the same fleets, queues and planets. Nothing may
 * happen twice because of it: no fleet lands twice, no debris is loaded
 * twice, no level rises twice, and no write from an old reading undoes what
 * another view took meanwhile (resources and ships doubled).
 */
#[CoversClass(MissionControlLib::class)]
#[CoversClass(UpdatesLibrary::class)]
#[CoversClass(PlanetStock::class)]
#[CoversClass(BuildingQueueService::class)]
#[CoversClass(ResearchQueueService::class)]
class ResourceDuplicationMysqlTest extends MysqlTestCase
{
    private User $player;

    /** @var array{planet_id: int, galaxy: int, system: int, planet: int} */
    private array $home;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);

        $player = app(RegistrationService::class)->register(['name' => 'Doubler', 'email' => 'doubler@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $player);
        $this->player = $player;
        $planet = DB::table('planets')->where('planet_user_id', $player->id)->first();
        $this->assertNotNull($planet);
        $this->home = ['planet_id' => self::int($planet->planet_id), 'galaxy' => self::int($planet->planet_galaxy), 'system' => self::int($planet->planet_system), 'planet' => self::int($planet->planet_planet)];
        DB::table('planets')->where('planet_id', $this->home['planet_id'])->update(['planet_metal' => 1000, 'planet_crystal' => 1000, 'planet_deuterium' => 1000, 'planet_last_update' => time()]);
    }

    public function testAReturningFleetLandsOnce(): void
    {
        $fleet = $this->fleet(['fleet_mission' => 3, 'fleet_mess' => 1, 'fleet_resource_metal' => 5000, 'fleet_end_time' => time() - 1]);
        $due = $this->due('getReturningFleets');

        // two views saw the same fleet
        $this->process($due);
        $this->process($due);

        $this->assertSame(10, $this->ships('ship_small_cargo_ship'));
        $this->assertSame(6000, $this->resource('planet_metal'));
        $this->assertSame(0, DB::table('fleets')->where('fleet_id', $fleet)->count());
    }

    public function testADebrisFieldIsLoadedOnce(): void
    {
        DB::table('planets')->where('planet_id', $this->home['planet_id'])->update(['planet_debris_metal' => 3000, 'planet_debris_crystal' => 0]);
        $fleet = $this->fleet([
            'fleet_mission' => 8, 'fleet_array' => FleetShips::encode([Ships::SHIP_RECYCLER => 5]), 'fleet_amount' => 5,
            'fleet_start_time' => time() - 1, 'fleet_end_time' => time() + 600,
        ]);
        $due = $this->due('getArrivingFleets');

        $this->process($due);
        $this->process($due);

        $this->assertSame(3000, self::int(DB::table('fleets')->where('fleet_id', $fleet)->value('fleet_resource_metal')));
        $this->assertSame(0, self::int(DB::table('planets')->where('planet_id', $this->home['planet_id'])->value('planet_debris_metal')));
    }

    public function testATargetStaysLockedWhileAMissionRuns(): void
    {
        DB::table('planets')->where('planet_id', $this->home['planet_id'])->update(['planet_debris_metal' => 3000]);
        $this->fleet([
            'fleet_mission' => 8, 'fleet_array' => FleetShips::encode([Ships::SHIP_RECYCLER => 5]), 'fleet_amount' => 5,
            'fleet_start_time' => time() - 1, 'fleet_end_time' => time() + 600,
        ]);
        $other = null;

        // while the first recycler loads, a second view (another connection) tries to read the field
        $this->interject('`planet_debris_metal` = `planet_debris_metal` - ?', function () use (&$other): void {
            $other = $this->otherConnectionReads($this->home['planet_id']);
        });
        $this->process($this->due('getArrivingFleets'));

        $this->assertSame('waited', $other);
    }

    public function testProductionNeverUndoesWhatWasTakenMeanwhile(): void
    {
        $this->dock(10);
        DB::table('planets')->where('planet_id', $this->home['planet_id'])->update(['planet_last_update' => time() - 3600]);
        [$user, $planet] = $this->read();

        // a fleet leaves with cargo and ships while this view computes the production
        $this->interject('`planet_metal` = `planet_metal` + ?', function (): void {
            DB::table('planets')->where('planet_id', $this->home['planet_id'])->update(['planet_metal' => DB::raw('planet_metal - 900')]);
            DB::table('ships')->where('ship_planet_id', $this->home['planet_id'])->update(['ship_small_cargo_ship' => DB::raw('ship_small_cargo_ship - 10')]);
        });
        UpdatesLibrary::updatePlanetResources($user, $planet, time());

        $produced = $this->resource('planet_metal') - 100;
        $this->assertGreaterThan(0, $produced);
        $this->assertLessThan(900, $produced);
        $this->assertSame(0, $this->ships('ship_small_cargo_ship'));
    }

    public function testBuiltShipsAreAddedNotSet(): void
    {
        $this->dock(10);
        // a small cargo ship ordered long ago is finished by this view
        DB::table('planets')->where('planet_id', $this->home['planet_id'])->update(['planet_last_update' => time() - 86400, 'planet_b_hangar_id' => '202,1;', 'planet_b_hangar' => 0]);
        [$user, $planet] = $this->read();

        // the ten docked ones leave while this view finishes the new one
        $this->interject('`planet_metal` = `planet_metal` + ?', function (): void {
            DB::table('ships')->where('ship_planet_id', $this->home['planet_id'])->update(['ship_small_cargo_ship' => DB::raw('ship_small_cargo_ship - 10')]);
        });
        UpdatesLibrary::updatePlanetResources($user, $planet, time());

        $this->assertSame(1, $this->ships('ship_small_cargo_ship'));
    }

    public function testASecondViewDoesNotProduceTwice(): void
    {
        DB::table('planets')->where('planet_id', $this->home['planet_id'])->update(['planet_last_update' => time() - 3600]);
        [$user, $first] = $this->read();
        [, $second] = $this->read();

        UpdatesLibrary::updatePlanetResources($user, $first, time());
        $once = $this->resource('planet_metal');
        UpdatesLibrary::updatePlanetResources($user, $second, time());

        $this->assertSame($once, $this->resource('planet_metal'));
        // the second view shows what is stored
        $this->assertSame($once, self::int($second['planet_metal']));
    }

    public function testShipsAndCargoAreTakenOnlyIfStillThere(): void
    {
        $this->dock(10);
        $stock = app(PlanetStock::class);
        $stock->take($this->home['planet_id'], ['ship_small_cargo_ship' => 10], ['metal' => 600]);

        try {
            // the same ships again (a second request at the same moment)
            $stock->take($this->home['planet_id'], ['ship_small_cargo_ship' => 10], ['metal' => 300]);
            $this->fail('taken twice');
        } catch (NoLongerOnPlanet) {
            $this->assertSame(0, $this->ships('ship_small_cargo_ship'));
            $this->assertSame(400, $this->resource('planet_metal'));
        }
    }

    public function testABuildingLevelRisesOnce(): void
    {
        $planet = Planets::with('buildings')->findOrFail($this->home['planet_id']);
        BuildingQueue::create(['planet_id' => $planet->planet_id, 'position' => 1, 'building_id' => 1, 'target_level' => 1, 'mode' => 'build', 'duration' => 10, 'end_time' => time() - 1]);
        $service = app(BuildingQueueService::class);

        // a second view saw the same finished order and gets to it first
        $this->interject('delete from `xgp_building_queue', function () use ($service): void {
            $service->processCompletions(Planets::with('buildings')->findOrFail($this->home['planet_id']), []);
        });
        $service->processCompletions($planet, []);

        $this->assertSame(1, self::int(DB::table('buildings')->where('building_planet_id', $this->home['planet_id'])->value('building_metal_mine')));
    }

    public function testAResearchLevelRisesOnce(): void
    {
        ResearchQueue::create(['user_id' => $this->player->id, 'planet_id' => $this->home['planet_id'], 'position' => 1, 'tech_id' => 106, 'target_level' => 1, 'duration' => 10, 'end_time' => time() - 1]);
        $service = app(ResearchQueueService::class);

        $this->interject('delete from `xgp_research_queue', function () use ($service): void {
            $service->processCompletions(User::findOrFail($this->player->id));
        });
        $service->processCompletions(User::findOrFail($this->player->id));

        $this->assertSame(1, self::int(DB::table('research')->where('research_user_id', $this->player->id)->value('research_espionage_technology')));
    }

    public function testAQueuedResearchThatCannotBePaidIsDroppedNotPaidBelowZero(): void
    {
        DB::table('planets')->where('planet_id', $this->home['planet_id'])->update(['planet_metal' => 0, 'planet_crystal' => 0, 'planet_deuterium' => 0]);
        ResearchQueue::create(['user_id' => $this->player->id, 'planet_id' => $this->home['planet_id'], 'position' => 1, 'tech_id' => 106, 'target_level' => 1, 'duration' => 10, 'end_time' => time() - 1]);
        ResearchQueue::create(['user_id' => $this->player->id, 'planet_id' => $this->home['planet_id'], 'position' => 2, 'tech_id' => 113, 'target_level' => 1, 'duration' => 10, 'end_time' => time() + 100]);

        app(ResearchQueueService::class)->processCompletions(User::findOrFail($this->player->id));

        $this->assertSame(0, ResearchQueue::where('user_id', $this->player->id)->count());
        $this->assertSame(0, $this->resource('planet_metal'));
        $this->assertGreaterThanOrEqual(0, $this->resource('planet_crystal'));
    }

    public function testACancelledBuildingIsRefundedOnce(): void
    {
        $planet = Planets::with('buildings')->findOrFail($this->home['planet_id']);
        $service = app(BuildingQueueService::class);
        $this->assertTrue($service->add($planet, ['id' => $this->player->id], 1, 'build'));
        $paid = $this->resource('planet_metal');

        // the same click from a second view, which gets there first
        $this->interject('delete from `xgp_building_queue', function () use ($service): void {
            $service->cancelFirst(Planets::with('buildings')->findOrFail($this->home['planet_id']), []);
        });
        $service->cancelFirst(Planets::with('buildings')->findOrFail($this->home['planet_id']), []);

        $this->assertSame(1000, $this->resource('planet_metal'));
        $this->assertGreaterThan($paid, $this->resource('planet_metal'));
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function fleet(array $changes): int
    {
        return (int) DB::table('fleets')->insertGetId($changes + [
            'fleet_owner' => $this->player->id, 'fleet_mission' => 3, 'fleet_amount' => 10,
            'fleet_array' => FleetShips::encode([Ships::SHIP_SMALL_CARGO_SHIP => 10]),
            'fleet_start_time' => time() - 100, 'fleet_start_galaxy' => $this->home['galaxy'], 'fleet_start_system' => $this->home['system'],
            'fleet_start_planet' => $this->home['planet'], 'fleet_start_type' => 1, 'fleet_end_time' => time() - 1, 'fleet_end_stay' => 0,
            'fleet_end_galaxy' => $this->home['galaxy'], 'fleet_end_system' => $this->home['system'], 'fleet_end_planet' => $this->home['planet'],
            'fleet_end_type' => 1, 'fleet_target_obj' => 0, 'fleet_resource_metal' => 0, 'fleet_resource_crystal' => 0, 'fleet_resource_deuterium' => 0,
            'fleet_fuel' => 0, 'fleet_target_owner' => $this->player->id, 'fleet_group' => '0', 'fleet_mess' => 0, 'fleet_creation' => time() - 200,
        ]);
    }

    /**
     * The fleets a view sees as due.
     *
     * @return array<int, array<string, mixed>>
     */
    private function due(string $which): array
    {
        $fleets = (new ReflectionMethod(MissionControlLib::class, $which))->invoke(new MissionControlLib());
        $this->assertIsArray($fleets);
        $this->assertNotEmpty($fleets);

        /** @var array<int, array<string, mixed>> $fleets */
        return $fleets;
    }

    /**
     * @param array<int, array<string, mixed>> $fleets
     */
    private function process(array $fleets): void
    {
        (new ReflectionMethod(MissionControlLib::class, 'processMissions'))->invoke(new MissionControlLib(), $fleets);
    }

    /**
     * The player and the planet as a game page reads them.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function read(): array
    {
        $planet = (array) DB::table('planets AS p')
            ->join('buildings AS b', 'b.building_planet_id', '=', 'p.planet_id')
            ->join('ships AS s', 's.ship_planet_id', '=', 'p.planet_id')
            ->join('defenses AS d', 'd.defense_planet_id', '=', 'p.planet_id')
            ->where('p.planet_id', $this->home['planet_id'])->first();
        $user = (array) DB::table('users AS u')
            ->join('research AS r', 'r.research_user_id', '=', 'u.id')
            ->join('premium AS pr', 'pr.premium_user_id', '=', 'u.id')
            ->join('preferences AS pf', 'pf.preference_user_id', '=', 'u.id')
            ->where('u.id', $this->player->id)->first();

        return [$user, $planet];
    }

    /**
     * Another connection, as another view would: does it wait for the lock?
     */
    private function otherConnectionReads(int $planetId): string
    {
        $setting = static fn (string $key): string => is_string($value = config('database.connections.mysql_test.' . $key)) ? $value : '';
        $pdo = new \PDO('mysql:host=' . $setting('host') . ';dbname=' . $setting('database'), $setting('username'), $setting('password'));
        $pdo->exec('SET innodb_lock_wait_timeout = 1');

        try {
            $pdo->query('SELECT `planet_debris_metal` FROM `xgp_planets` WHERE `planet_id` = ' . $planetId . ' FOR UPDATE');

            return 'read at once';
        } catch (\PDOException) {
            return 'waited';
        }
    }

    private function dock(int $cargoShips): void
    {
        DB::table('ships')->where('ship_planet_id', $this->home['planet_id'])->update(['ship_small_cargo_ship' => $cargoShips]);
    }

    private function ships(string $column): int
    {
        return self::int(DB::table('ships')->where('ship_planet_id', $this->home['planet_id'])->value($column));
    }

    private function resource(string $column): int
    {
        return self::int(DB::table('planets')->where('planet_id', $this->home['planet_id'])->value($column));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Runs $other once, right before the first query that holds $sql.
     */
    private function interject(string $sql, callable $other): void
    {
        $done = false;
        DB::connection()->beforeExecuting(function (string $query) use ($sql, $other, &$done): void {
            if (!$done && str_contains($query, $sql)) {
                $done = true;
                $other();
            }
        });
    }
}
