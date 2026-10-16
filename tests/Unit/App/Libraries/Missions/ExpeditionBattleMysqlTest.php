<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Support\FleetShips;
use App\Libraries\Missions\ExpeditionBattle;
use App\Models\Fleets;
use App\Models\User;
use App\Services\Game\Formulas\ExpeditionService;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Pirates or aliens attack an expedition (they ended like "nothing"
 * before): the survivors fly home, a destroyed fleet is gone, the losses
 * count, and the player gets the combat report.
 */
#[CoversClass(ExpeditionBattle::class)]
class ExpeditionBattleMysqlTest extends MysqlTestCase
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
        DB::table('users_statistics')->where('user_statistic_user_id', $player->id)->update(['user_statistic_ships_points' => 100000, 'user_statistic_military_points' => 100000]);
    }

    public function testAStrongExpeditionBeatsThePiratesAndFliesHome(): void
    {
        $this->enemy([204 => 5]);
        $fleetId = $this->expedition([207 => 50]);

        app(ExpeditionBattle::class)->fight($this->fleetRow($fleetId), 'pirates');

        $fleet = Fleets::findOrFail($fleetId);
        $this->assertSame(1, $fleet->fleet_mess);
        $survivors = FleetShips::decode($fleet->fleet_array);
        $this->assertSame([207], array_keys($survivors));
        $this->assertGreaterThan(0, $survivors[207]);
        $this->assertReportSent();
    }

    public function testAnExpeditionDestroyedByAliensIsGoneAndItsLossesCount(): void
    {
        $this->enemy([207 => 200]);
        $fleetId = $this->expedition([204 => 1]);

        app(ExpeditionBattle::class)->fight($this->fleetRow($fleetId), 'aliens');

        $this->assertNull(Fleets::find($fleetId));
        $points = DB::table('users_statistics')->where('user_statistic_user_id', $this->player->id)->value('user_statistic_ships_points');
        $this->assertLessThan(100000, is_numeric($points) ? (float) $points : 100000);
        $this->assertReportSent();
    }

    /**
     * A fixed enemy fleet instead of the drawn one.
     *
     * @param array<int, int> $ships
     */
    private function enemy(array $ships): void
    {
        $this->app->instance(ExpeditionService::class, new class (app(SettingsService::class), $ships) extends ExpeditionService {
            /**
             * @param array<int, int> $ships
             */
            public function __construct(SettingsService $settings, private array $ships)
            {
                parent::__construct($settings);
            }

            public function getEnemyFleet(string $enemy, string $size, array $ships): array
            {
                return $this->ships;
            }
        });
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
        return (array) DB::table('fleets')->where('fleet_id', $fleetId)->first();
    }

    private function assertReportSent(): void
    {
        $this->assertSame((string) $this->player->id, DB::table('reports')->value('report_owners'));
        $this->assertSame(1, DB::table('messages')->where('message_receiver', $this->player->id)->where('message_type', 1)->count());
    }
}
