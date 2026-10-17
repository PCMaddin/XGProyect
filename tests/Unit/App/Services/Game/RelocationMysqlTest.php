<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Libraries\MissionControlLib;
use App\Libraries\PlanetLib;
use App\Models\User;
use App\Services\Game\RelocationService;
use App\Services\RegistrationService;
use App\Support\FleetShips;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Relocating a planet: the target is reserved for 24 hours, then the planet
 * and its moon move, the dark matter is taken, the ships follow as a
 * deployment, holding fleets fly home and the jump gate rests; orders or
 * own fleets cancel it.
 */
#[CoversClass(RelocationService::class)]
class RelocationMysqlTest extends MysqlTestCase
{
    private User $player;

    private User $neighbour;

    private int $planet;

    private int $moon;

    /** @var array{0: int, 1: int, 2: int} */
    private array $origin;

    /** @var array{0: int, 1: int, 2: int} */
    private array $target;

    protected function setUp(): void
    {
        parent::setUp();

        config(['version.files' => '4.0.0']);
        $this->seed(OptionsTableSeeder::class);

        $player = app(RegistrationService::class)->register(['name' => 'Mover', 'email' => 'mover@example.com', 'password' => 'secret-password']);
        $neighbour = app(RegistrationService::class)->register(['name' => 'Neighbour', 'email' => 'neighbour@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $player);
        $this->assertInstanceOf(User::class, $neighbour);
        $this->player = $player;
        $this->neighbour = $neighbour;
        $this->planet = $this->homeOf($player);

        $home = DB::table('planets')->where('planet_id', $this->planet)->first();
        $this->assertNotNull($home);
        $this->origin = [(int) $home->planet_galaxy, (int) $home->planet_system, (int) $home->planet_planet];
        $this->target = [$this->origin[0], $this->origin[1] === 400 ? 401 : 400, $this->origin[2]];

        $this->assertTrue(app(PlanetLib::class)->setNewMoon($this->origin[0], $this->origin[1], $this->origin[2], $player->id, 'Luna', 0, 4000, 1));
        $moon = DB::table('planets')->where('planet_user_id', $player->id)->where('planet_type', 3)->value('planet_id');
        $this->assertIsNumeric($moon);
        $this->moon = (int) $moon;

        DB::table('premium')->where('premium_user_id', $player->id)->update(['premium_dark_matter' => 300000]);
        DB::table('ships')->where('ship_planet_id', $this->planet)->update(['ship_light_fighter' => 10, 'ship_solar_satellite' => 5]);
        DB::table('ships')->where('ship_planet_id', $this->moon)->update(['ship_small_cargo_ship' => 3]);
        DB::table('buildings')->where('building_planet_id', $this->moon)->update(['building_jump_gate' => 1]);
        DB::table('planets')->where('planet_id', $this->planet)->update(['planet_debris_metal' => 5000]);
    }

    public function testTheTargetMustBeFreeInTheSameBandAndPaidFor(): void
    {
        $service = app(RelocationService::class);
        $otherBand = $this->origin[2] <= 3 ? 15 : 1;
        $neighbourHome = DB::table('planets')->where('planet_id', $this->homeOf($this->neighbour))->first();
        $this->assertNotNull($neighbourHome);

        $this->assertSame('rl_error_band', $this->start($this->target[0], $this->target[1], $otherBand));
        $this->assertSame('rl_error_taken', $this->start((int) $neighbourHome->planet_galaxy, (int) $neighbourHome->planet_system, (int) $neighbourHome->planet_planet));
        $this->assertSame('rl_error_target', $this->start(0, 1, $this->origin[2]));

        DB::table('premium')->where('premium_user_id', $this->player->id)->update(['premium_dark_matter' => 1000]);
        $this->assertSame('rl_error_dark_matter', $this->start(...$this->target));

        $this->assertTrue($service->sameBand(4, 12));
        $this->assertFalse($service->sameBand(3, 4));
        $this->assertFalse($service->sameBand(13, 12));
    }

    public function testOrdersAndOwnFleetsBlockTheStart(): void
    {
        DB::table('building_queues')->insert([
            'planet_id' => $this->moon, 'position' => 1, 'building_id' => 41, 'target_level' => 1,
            'mode' => 'build', 'duration' => 60, 'end_time' => time() + 60,
        ]);
        $this->assertSame('rl_error_building', $this->start(...$this->target));

        DB::table('building_queues')->delete();
        $this->ownFleet();
        $this->assertSame('rl_error_fleets', $this->start(...$this->target));
    }

    public function testTheReservedPositionCannotBeColonized(): void
    {
        $this->assertNull($this->start(...$this->target));

        $this->assertFalse(app(PlanetLib::class)->setNewPlanet($this->target[0], $this->target[1], $this->target[2], $this->neighbour->id));
        $this->assertSame('rl_error_running', $this->start(...$this->target));
        // nothing is paid before the move
        $this->assertSame(300000, $this->darkMatter());
    }

    public function testThePlanetAndItsMoonMoveAndTheShipsFollow(): void
    {
        $this->assertNull($this->start(...$this->target));
        $holding = $this->holdingFleet();
        $this->due();

        app(RelocationService::class)->settleDue(time());

        foreach ([$this->planet, $this->moon] as $id) {
            $row = DB::table('planets')->where('planet_id', $id)->first();
            $this->assertNotNull($row);
            $this->assertSame($this->target, [(int) $row->planet_galaxy, (int) $row->planet_system, (int) $row->planet_planet]);
            $this->assertSame(0, (int) $row->planet_relocate_at);
        }

        $this->assertSame(60000, $this->darkMatter());
        $this->assertSame(0, $this->column('planets', 'planet_id', $this->planet, 'planet_debris_metal'));
        $this->assertSame($this->target[1], $this->column('users', 'id', $this->player->id, 'system'));
        // the gate rests for 24 hours
        $this->assertGreaterThan(time() + 80000, $this->column('planets', 'planet_id', $this->moon, 'planet_last_jump_time'));
        // holding fleets fly home
        $this->assertSame(1, $this->column('fleets', 'fleet_id', $holding, 'fleet_mess'));

        // satellites move with the planet, the other ships deploy
        $this->assertSame(0, $this->ships($this->planet, 'ship_light_fighter'));
        $this->assertSame(5, $this->ships($this->planet, 'ship_solar_satellite'));
        $this->assertSame(0, $this->ships($this->moon, 'ship_small_cargo_ship'));

        $fleets = DB::table('fleets')->where('fleet_owner', $this->player->id)->where('fleet_mission', 4)->orderBy('fleet_start_type')->get();
        $this->assertCount(2, $fleets);
        $this->assertSame([[204 => 10], [202 => 3]], $fleets->map(fn (object $fleet): array => FleetShips::decode((string) $fleet->fleet_array))->all());
        $first = $fleets->first();
        $this->assertNotNull($first);
        $this->assertSame(0, (int) $first->fleet_fuel);
        $this->assertSame($this->origin[1], (int) $first->fleet_start_system);
        $this->assertSame($this->target[1], (int) $first->fleet_end_system);
        $this->assertGreaterThan(time(), (int) $first->fleet_start_time);

        // they land at the new position
        DB::table('fleets')->where('fleet_owner', $this->player->id)->where('fleet_mission', 4)->update(['fleet_start_time' => time() - 1]);
        (new MissionControlLib())->arrivingFleets();

        $this->assertSame(10, $this->ships($this->planet, 'ship_light_fighter'));
        $this->assertSame(3, $this->ships($this->moon, 'ship_small_cargo_ship'));
    }

    public function testAnOwnFleetAtTheMoveCancelsTheRelocation(): void
    {
        $this->assertNull($this->start(...$this->target));
        $this->ownFleet();
        $this->due();

        $this->assertSame('rl_error_fleets', app(RelocationService::class)->settle($this->planet, time()));

        $row = DB::table('planets')->where('planet_id', $this->planet)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->origin, [(int) $row->planet_galaxy, (int) $row->planet_system, (int) $row->planet_planet]);
        $this->assertSame(0, (int) $row->planet_relocate_at);
        $this->assertSame(300000, $this->darkMatter());
        $this->assertSame(1, DB::table('messages')->where('message_receiver', $this->player->id)->count());
    }

    public function testVacationModeCancelsTheRelocation(): void
    {
        $this->assertNull($this->start(...$this->target));
        DB::table('preferences')->where('preference_user_id', $this->player->id)->update(['preference_vacation_mode' => time()]);
        $this->due();

        $this->assertSame('rl_error_vacation', app(RelocationService::class)->settle($this->planet, time()));
        $this->assertSame($this->origin[1], $this->column('planets', 'planet_id', $this->planet, 'planet_system'));
    }

    private function start(int $galaxy, int $system, int $position): ?string
    {
        return app(RelocationService::class)->start($this->player->id, $this->planet, $galaxy, $system, $position, time());
    }

    private function due(): void
    {
        DB::table('planets')->where('planet_id', $this->planet)->update(['planet_relocate_at' => time() - 1]);
    }

    private function darkMatter(): int
    {
        return $this->column('premium', 'premium_user_id', $this->player->id, 'premium_dark_matter');
    }

    private function ships(int $planetId, string $column): int
    {
        return $this->column('ships', 'ship_planet_id', $planetId, $column);
    }

    private function column(string $table, string $key, int $id, string $column): int
    {
        $value = DB::table($table)->where($key, $id)->value($column);
        $this->assertIsNumeric($value);

        return (int) $value;
    }

    private function homeOf(User $user): int
    {
        $id = DB::table('planets')->where('planet_user_id', $user->id)->where('planet_type', 1)->value('planet_id');
        $this->assertIsNumeric($id);

        return (int) $id;
    }

    private function ownFleet(): void
    {
        DB::table('fleets')->insert([
            'fleet_owner' => $this->player->id, 'fleet_target_owner' => 0, 'fleet_mission' => 3,
            'fleet_amount' => 1, 'fleet_array' => FleetShips::encode([202 => 1]),
            'fleet_start_time' => time() + 600, 'fleet_end_stay' => 0, 'fleet_end_time' => time() + 1200,
            'fleet_start_galaxy' => $this->origin[0], 'fleet_start_system' => $this->origin[1], 'fleet_start_planet' => $this->origin[2], 'fleet_start_type' => 3,
            'fleet_end_galaxy' => 9, 'fleet_end_system' => 9, 'fleet_end_planet' => 9, 'fleet_end_type' => 1,
            'fleet_mess' => 0, 'fleet_group' => 0, 'fleet_creation' => time(),
        ]);
    }

    private function holdingFleet(): int
    {
        $home = DB::table('planets')->where('planet_id', $this->homeOf($this->neighbour))->first();
        $this->assertNotNull($home);

        return (int) DB::table('fleets')->insertGetId([
            'fleet_owner' => $this->neighbour->id, 'fleet_target_owner' => $this->player->id, 'fleet_mission' => 5,
            'fleet_amount' => 1, 'fleet_array' => FleetShips::encode([204 => 1]),
            'fleet_start_time' => time() - 600, 'fleet_end_stay' => time() + 3600, 'fleet_end_time' => time() + 4200,
            'fleet_start_galaxy' => $home->planet_galaxy, 'fleet_start_system' => $home->planet_system, 'fleet_start_planet' => $home->planet_planet, 'fleet_start_type' => 1,
            'fleet_end_galaxy' => $this->origin[0], 'fleet_end_system' => $this->origin[1], 'fleet_end_planet' => $this->origin[2], 'fleet_end_type' => 1,
            'fleet_mess' => 0, 'fleet_group' => 0, 'fleet_creation' => time() - 1200,
        ], 'fleet_id');
    }
}
