<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\MissionControlLib;
use App\Libraries\Missions\CombatMission;
use App\Models\User;
use App\Services\Game\WreckFieldService;
use App\Services\RegistrationService;
use App\Support\FleetShips;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * A real attack leaves wreck fields: for the defender at the attacked
 * planet, and for an attacking general at their home planet, repairable
 * once the fleet is back.
 */
#[CoversClass(CombatMission::class)]
#[CoversClass(WreckFieldService::class)]
class WreckFieldBattleMysqlTest extends MysqlTestCase
{
    private User $attacker;

    private User $defender;

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
    }

    public function testTheDefenderGetsAWreckField(): void
    {
        DB::table('ships')->where('ship_planet_id', $this->homeOf($this->defender))->update(['ship_battleship' => 20]);
        $this->attack([214 => 300]);

        $field = app(WreckFieldService::class)->field($this->homeOf($this->defender));
        $this->assertNotNull($field);
        $this->assertSame($this->defender->id, $field['user_id']);
        $this->assertSame([207 => 14], $field['ships']);
        $this->assertNull(app(WreckFieldService::class)->field($this->homeOf($this->attacker)));
    }

    public function testAnAttackingGeneralGetsAWreckFieldAtHome(): void
    {
        DB::table('users')->where('id', $this->attacker->id)->update(['player_class' => 2]);
        DB::table('defenses')->where('defense_planet_id', $this->homeOf($this->defender))->update(['defense_plasma_turret' => 200]);
        $this->attack([204 => 100]);

        $field = app(WreckFieldService::class)->field($this->homeOf($this->attacker));
        $this->assertNotNull($field);
        $this->assertSame([204 => 70], $field['ships']);
        // repairable when the fleet would have been back
        $this->assertEqualsWithDelta(time() + 3600, $field['repairable_from'], 5);
        $this->assertNull(app(WreckFieldService::class)->field($this->homeOf($this->defender)));
    }

    private function homeOf(User $user): int
    {
        $id = DB::table('planets')->where('planet_user_id', $user->id)->where('planet_type', 1)->value('planet_id');
        $this->assertIsNumeric($id);

        return (int) $id;
    }

    /**
     * @param array<int, int> $ships
     */
    private function attack(array $ships): void
    {
        $home = DB::table('planets')->where('planet_id', $this->homeOf($this->attacker))->first(['planet_galaxy', 'planet_system', 'planet_planet']);
        $target = DB::table('planets')->where('planet_id', $this->homeOf($this->defender))->first(['planet_galaxy', 'planet_system', 'planet_planet']);
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

        $this->assertSame(1, DB::table('reports')->count());
    }
}
