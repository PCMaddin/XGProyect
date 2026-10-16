<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\MissionControlLib;
use App\Libraries\Missions\CombatMission;
use App\Libraries\Missions\FleetEscape;
use App\Models\User;
use App\Services\RegistrationService;
use App\Support\FleetShips;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * The fleet escape in a real attack: the defender's ships flee from an
 * attacker at least 5 times as strong (3 times with an admiral), for 1.5
 * times the fuel of a flight to the next position; death stars, probes and
 * satellites stay; the player can switch it off.
 */
#[CoversClass(CombatMission::class)]
#[CoversClass(FleetEscape::class)]
class FleetEscapeMysqlTest extends MysqlTestCase
{
    private User $attacker;

    private User $defender;

    private int $target;

    protected function setUp(): void
    {
        parent::setUp();

        config(['version.files' => '4.0.0']);
        $this->seed(OptionsTableSeeder::class);
        DB::table('options')->where('name', 'noobprotection')->update(['value' => '1']);
        DB::table('options')->where('name', 'noobprotectiontime')->update(['value' => '5000']);

        $attacker = app(RegistrationService::class)->register(['name' => 'Attacker', 'email' => 'attacker@example.com', 'password' => 'secret-password']);
        $defender = app(RegistrationService::class)->register(['name' => 'Defender', 'email' => 'defender@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $attacker);
        $this->assertInstanceOf(User::class, $defender);
        $this->attacker = $attacker;
        $this->defender = $defender;
        $this->target = $this->homeOf($defender);

        DB::table('ships')->where('ship_planet_id', $this->target)->update(['ship_light_fighter' => 20, 'ship_espionage_probe' => 5]);
        DB::table('planets')->where('planet_id', $this->target)->update(['planet_deuterium' => 10000]);
    }

    public function testTheFleetFleesFromAFarStrongerAttacker(): void
    {
        // 50 cruisers (1.450.000) against 20 light fighters and 5 probes (80.000 + 1.250)
        $this->attack([206 => 50]);

        $this->assertSame(20, $this->ships('ship_light_fighter'));
        // probes do not flee
        $this->assertSame(0, $this->ships('ship_espionage_probe'));
        $this->assertLessThan(10000, $this->deuterium());
        $this->assertStringContainsString(sprintf(__('game/attack.at_fleet_fled'), 20), $this->report());
    }

    public function testNoEscapeFromAWeakerAttackerOrWhenSwitchedOff(): void
    {
        // 3 cruisers: 87.000 against 81.250, not 5 times as strong
        $this->attack([206 => 3]);
        $this->assertNotSame('', $this->report());
        $this->assertStringNotContainsString(sprintf(__('game/attack.at_fleet_fled'), 20), $this->report());

        DB::table('reports')->delete();
        DB::table('ships')->where('ship_planet_id', $this->target)->update(['ship_light_fighter' => 20]);
        DB::table('preferences')->where('preference_user_id', $this->defender->id)->update(['preference_fleet_escape' => 0]);
        $this->attack([206 => 50]);
        $this->assertSame(0, $this->ships('ship_light_fighter'));
    }

    public function testWithAnAdmiralThreeTimesIsEnough(): void
    {
        // 10 cruisers: 290.000, about 3.6 times the defender
        DB::table('premium')->where('premium_user_id', $this->defender->id)->update(['premium_officier_admiral' => time() + 86400]);
        $this->attack([206 => 10]);

        $this->assertSame(20, $this->ships('ship_light_fighter'));
    }

    private function ships(string $column): int
    {
        $value = DB::table('ships')->where('ship_planet_id', $this->target)->value($column);
        $this->assertIsNumeric($value);

        return (int) $value;
    }

    private function report(): string
    {
        $value = DB::table('reports')->value('report_content');

        return is_string($value) ? $value : '';
    }

    private function deuterium(): float
    {
        $value = DB::table('planets')->where('planet_id', $this->target)->value('planet_deuterium');
        $this->assertIsNumeric($value);

        return (float) $value;
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
