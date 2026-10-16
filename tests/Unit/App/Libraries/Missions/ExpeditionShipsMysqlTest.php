<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Expedition;
use App\Models\Fleets;
use App\Models\User;
use App\Services\Game\Formulas\ExpeditionService;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use App\Support\FleetShips;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * An expedition that finds ships, as in the original: the found ships join
 * the fleet, which flies home, and the report lists them. Regression: a
 * deathstar in the fleet stopped the mission (undefined chance) or brought
 * a free one, and the finds were several times the fleet.
 */
#[CoversClass(Expedition::class)]
class ExpeditionShipsMysqlTest extends MysqlTestCase
{
    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config(['version.files' => '4.0.0']);
        $this->seed(OptionsTableSeeder::class);

        $player = app(RegistrationService::class)->register(['name' => 'Explorer', 'email' => 'explorer@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $player);
        $this->player = $player;

        // always ships, from a normal source with the factor 50; the draws:
        // normal source; heavy fighter, 3; large cargo, 2; small cargo, 1;
        // then three draws the rest (4.500) does not pay for
        $this->app->instance(ExpeditionService::class, new class (app(SettingsService::class)) extends ExpeditionService {
            /** @var list<int> */
            private array $randoms = [1, 4, 3, 3, 2, 1, 1, 3, 4, 3];

            public function getExpeditionResult(): string
            {
                return 'ships';
            }

            public function getShipSourceSizeMultChances(string $discoveryType): int
            {
                return 50;
            }

            protected function random(int $min, int $max): int
            {
                return max($min, min($max, array_shift($this->randoms) ?? $min));
            }
        });
    }

    public function testFoundShipsJoinTheFleetWhichFliesHome(): void
    {
        $fleetId = $this->expedition([203 => 100, 214 => 1]);

        app(Expedition::class)->expeditionMission($this->fleetRow($fleetId));

        // 100 large cargos and a deathstar: 51.000 points, for ship finds
        // capped at 1.250 (top player below 100.000 points): 62.500
        // structure points; findable up to the heavy fighter
        $fleet = Fleets::findOrFail($fleetId);
        $ships = FleetShips::decode($fleet->fleet_array);
        $this->assertSame(1, $fleet->fleet_mess);
        $this->assertSame([202 => 1, 203 => 102, 205 => 3, 214 => 1], $this->sorted($ships));
        $this->assertSame(array_sum($ships), $fleet->fleet_amount);

        $message = DB::table('messages')->where('message_receiver', $this->player->id)->value('message_text');
        $this->assertIsString($message);
        $this->assertStringContainsString(__('game/ships.ship_heavy_fighter') . ': 3', $message);
    }

    /**
     * @param array<int, int> $ships
     *
     * @return array<int, int>
     */
    private function sorted(array $ships): array
    {
        ksort($ships);

        return $ships;
    }

    /**
     * @param array<int, int> $ships
     */
    private function expedition(array $ships): int
    {
        $home = DB::table('planets')->where('planet_user_id', $this->player->id)->first(['planet_galaxy', 'planet_system', 'planet_planet']);
        $this->assertNotNull($home);

        return DB::table('fleets')->insertGetId([
            'fleet_owner' => $this->player->id, 'fleet_mission' => 15, 'fleet_amount' => array_sum($ships), 'fleet_array' => FleetShips::encode($ships),
            'fleet_start_time' => time() - 60, 'fleet_end_stay' => time() - 30, 'fleet_end_time' => time() + 600,
            'fleet_start_galaxy' => $home->planet_galaxy, 'fleet_start_system' => $home->planet_system, 'fleet_start_planet' => $home->planet_planet, 'fleet_start_type' => 1,
            'fleet_end_galaxy' => $home->planet_galaxy, 'fleet_end_system' => $home->planet_system, 'fleet_end_planet' => 16, 'fleet_end_type' => 1,
            'fleet_mess' => 0,
        ], 'fleet_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function fleetRow(int $fleetId): array
    {
        return (array) DB::table('fleets')->where('fleet_id', $fleetId)->first() + ['research_hyperspace_technology' => 0];
    }
}
