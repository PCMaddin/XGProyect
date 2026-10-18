<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Core\Enumerators\MissionsEnumerator as Missions;
use App\Core\Enumerators\PlanetTypesEnumerator as PlanetTypes;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Libraries\MissionControlLib;
use App\Libraries\Missions\Transport;
use App\Models\User;
use App\Services\RegistrationService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Resources delivered to another player are recorded for the cheat
 * detection (pushing); deliveries to one's own planets are not.
 */
#[CoversClass(Transport::class)]
class TransportTrailMysqlTest extends MysqlTestCase
{
    public function testADeliveryToAnotherPlayerIsRecorded(): void
    {
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        $sender = $this->player('Sender');
        $receiver = $this->player('Receiver');

        $this->transport($sender, $receiver, $receiver);
        $this->transport($sender, $sender, $sender);
        (new MissionControlLib())->arrivingFleets();

        $this->assertEquals(
            [['from_user' => $sender->id, 'to_user' => $receiver->id, 'metal' => 5000, 'crystal' => 3000, 'deuterium' => 1000]],
            DB::table('resource_transfers')->get(['from_user', 'to_user', 'metal', 'crystal', 'deuterium'])->map(static fn (object $row): array => (array) $row)->all()
        );
    }

    private function transport(User $owner, User $target, User $targetOwner): void
    {
        $from = DB::table('planets')->where('planet_user_id', $owner->id)->first();
        $to = DB::table('planets')->where('planet_user_id', $target->id)->first();
        $this->assertNotNull($from);
        $this->assertNotNull($to);

        DB::table('fleets')->insert([
            'fleet_owner' => $owner->id, 'fleet_mission' => Missions::TRANSPORT, 'fleet_amount' => 5,
            'fleet_array' => serialize([Ships::SHIP_SMALL_CARGO_SHIP => 5]),
            'fleet_start_galaxy' => $from->planet_galaxy, 'fleet_start_system' => $from->planet_system, 'fleet_start_planet' => $from->planet_planet,
            'fleet_start_type' => PlanetTypes::PLANET, 'fleet_start_time' => time() - 1, 'fleet_end_time' => time() + 600,
            'fleet_end_galaxy' => $to->planet_galaxy, 'fleet_end_system' => $to->planet_system, 'fleet_end_planet' => $to->planet_planet,
            'fleet_end_type' => PlanetTypes::PLANET, 'fleet_target_owner' => $targetOwner->id,
            'fleet_resource_metal' => 5000, 'fleet_resource_crystal' => 3000, 'fleet_resource_deuterium' => 1000,
            'fleet_creation' => time() - 600,
        ]);
    }

    private function player(string $name): User
    {
        $user = app(RegistrationService::class)->register(['name' => $name, 'email' => strtolower($name) . '@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }
}
