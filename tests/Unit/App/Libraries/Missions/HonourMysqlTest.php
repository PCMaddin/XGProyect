<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\MissionControlLib;
use App\Libraries\StatisticsLibrary;
use App\Libraries\Missions\CombatMission;
use App\Models\User;
use App\Services\Game\HonorService;
use App\Services\RegistrationService;
use App\Support\FleetShips;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Honour in real attacks: honourable fights win honour points and 75 % of
 * the resources, dishonourable ones cost honour, bandits lose everything,
 * fights against alliance members and inactive players are neutral.
 */
#[CoversClass(HonorService::class)]
#[CoversClass(CombatMission::class)]
class HonourMysqlTest extends MysqlTestCase
{
    private User $attacker;

    private User $defender;

    private int $target;

    protected function setUp(): void
    {
        parent::setUp();

        config(['version.files' => '4.0.0']);
        $this->seed(OptionsTableSeeder::class);

        $attacker = app(RegistrationService::class)->register(['name' => 'Attacker', 'email' => 'attacker@example.com', 'password' => 'secret-password']);
        $defender = app(RegistrationService::class)->register(['name' => 'Defender', 'email' => 'defender@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $attacker);
        $this->assertInstanceOf(User::class, $defender);
        $this->attacker = $attacker;
        $this->defender = $defender;

        $id = DB::table('planets')->where('planet_user_id', $defender->id)->where('planet_type', 1)->value('planet_id');
        $this->assertIsNumeric($id);
        $this->target = (int) $id;

        DB::table('ships')->where('ship_planet_id', $this->target)->update(['ship_light_fighter' => 100]);
        DB::table('planets')->where('planet_id', $this->target)->update(['planet_metal' => 1e6, 'planet_crystal' => 0, 'planet_deuterium' => 0, 'planet_last_update' => time() + 60]);
        DB::table('preferences')->update(['preference_fleet_escape' => 0]);
    }

    public function testTheHonourPointFormula(): void
    {
        $this->assertSame([0, 251, 3723], [HonorService::points(0), HonorService::points(1e6), HonorService::points(2e7)]);
    }

    public function testAnHonourableAttackWinsHonourAndThreeQuartersOfTheResources(): void
    {
        $this->military(10000, 9000);
        $this->attack([207 => 50, 203 => 300]);

        // 100 light fighters destroyed: 400.000 units
        $this->assertSame((float) HonorService::points(400000), $this->honour($this->attacker));
        $this->assertSame(0.0, $this->honour($this->defender));
        $this->assertSame(750000.0, $this->metalLooted());
    }

    public function testADishonourableAttackCostsHonourAndTheDefenderWinsForWhatHeDestroyed(): void
    {
        $this->military(1000000, 1000);
        DB::table('defenses')->where('defense_planet_id', $this->target)->update(['defense_plasma_turret' => 50]);
        $this->attack([204 => 3000]);

        $this->assertLessThan(0, $this->honour($this->attacker));
        $this->assertGreaterThan(0, $this->honour($this->defender));
    }

    public function testBanditsLoseEverythingAndNeutralFightsCountNothing(): void
    {
        $this->military(1000000, 1000);
        DB::table('users_statistics')->where('user_statistic_user_id', $this->defender->id)->update(['user_statistic_honor_points' => -600]);
        $this->assertSame('bandit', app(HonorService::class)->titleOf($this->defender->id));
        $this->attack([207 => 50, 203 => 300]);
        $this->assertSame(1e6, $this->metalLooted());

        // alliance members: neutral
        DB::table('fleets')->delete();
        DB::table('users_statistics')->update(['user_statistic_honor_points' => 0]);
        DB::table('planets')->where('planet_id', $this->target)->update(['planet_metal' => 1e6]);
        DB::table('ships')->where('ship_planet_id', $this->target)->update(['ship_light_fighter' => 100]);
        DB::table('users')->update(['ally_id' => 9]);
        $this->attack([207 => 50, 203 => 300]);
        $this->assertSame([0.0, 0.0], [$this->honour($this->attacker), $this->honour($this->defender)]);
        $this->assertSame(500000.0, $this->metalLooted());
    }

    public function testTheMilitaryRankingsCountWhatWasDestroyedAndLost(): void
    {
        $this->military(10000, 9000);
        $this->attack([207 => 50, 203 => 300]);

        $stat = fn (User $user, string $column): float => $this->number($user, 'user_statistic_military_' . $column . '_points');

        // 100 light fighters: 400 points at 1.000 resources a point
        $this->assertSame(400.0, $stat($this->defender, 'lost'));
        $this->assertSame(400.0, $stat($this->attacker, 'destroyed'));
        $this->assertSame($stat($this->attacker, 'lost'), $stat($this->defender, 'destroyed'));

        (new StatisticsLibrary())->makeStats();
        $this->assertSame(1.0, $this->number($this->attacker, 'user_statistic_military_destroyed_rank'));
        $this->assertSame(1.0, $this->number($this->defender, 'user_statistic_military_lost_rank'));
    }

    private function number(User $user, string $column): float
    {
        $value = DB::table('users_statistics')->where('user_statistic_user_id', $user->id)->value($column);
        $this->assertIsNumeric($value);

        return (float) $value;
    }

    private function military(int $attacker, int $defender): void
    {
        DB::table('users_statistics')->where('user_statistic_user_id', $this->attacker->id)->update(['user_statistic_military_points' => $attacker]);
        DB::table('users_statistics')->where('user_statistic_user_id', $this->defender->id)->update(['user_statistic_military_points' => $defender]);
    }

    private function honour(User $user): float
    {
        $value = DB::table('users_statistics')->where('user_statistic_user_id', $user->id)->value('user_statistic_honor_points');
        $this->assertIsNumeric($value);

        return (float) $value;
    }

    private function metalLooted(): float
    {
        $value = DB::table('fleets')->where('fleet_owner', $this->attacker->id)->value('fleet_resource_metal');
        $this->assertIsNumeric($value);

        return (float) $value;
    }

    /**
     * @param array<int, int> $ships
     */
    private function attack(array $ships): void
    {
        $home = DB::table('planets')->where('planet_user_id', $this->attacker->id)->where('planet_type', 1)->first(['planet_galaxy', 'planet_system', 'planet_planet']);
        $target = DB::table('planets')->where('planet_id', $this->target)->first(['planet_galaxy', 'planet_system', 'planet_planet']);
        $this->assertNotNull($home);
        $this->assertNotNull($target);

        DB::table('fleets')->insert([
            'fleet_owner' => $this->attacker->id, 'fleet_target_owner' => $this->defender->id, 'fleet_mission' => 1,
            'fleet_amount' => array_sum($ships), 'fleet_array' => FleetShips::encode($ships),
            'fleet_start_time' => time() - 60, 'fleet_end_stay' => 0, 'fleet_end_time' => time() + 3600,
            'fleet_start_galaxy' => $home->planet_galaxy, 'fleet_start_system' => $home->planet_system, 'fleet_start_planet' => $home->planet_planet, 'fleet_start_type' => 1,
            'fleet_end_galaxy' => $target->planet_galaxy, 'fleet_end_system' => $target->planet_system, 'fleet_end_planet' => $target->planet_planet, 'fleet_end_type' => 1,
            'fleet_mess' => 0, 'fleet_group' => 0,
        ]);

        (new MissionControlLib())->arrivingFleets();
    }
}
