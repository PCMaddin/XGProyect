<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Expedition;
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
 * An expedition counts for the load of its area, and a probe in the fleet
 * adds the logbook entry on how busy the area is to the report.
 */
#[CoversClass(Expedition::class)]
class ExpeditionAreaMysqlTest extends MysqlTestCase
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

        $this->app->instance(ExpeditionService::class, new class (app(SettingsService::class)) extends ExpeditionService {
            public function getExpeditionResult(): string
            {
                return 'darkMatter';
            }
        });
    }

    public function testAProbeReportsHowBusyTheArea(): void
    {
        [$fleetId, $system, $endStay] = $this->expedition([203 => 5, 210 => 1]);
        DB::table('expedition_areas')->insert(['galaxy' => 1, 'system' => $system, 'load' => 30, 'updated_at' => $endStay]);

        app(Expedition::class)->expeditionMission($this->fleetRow($fleetId));

        $this->assertStringContainsString(__('game/expedition.exp_status_crowded'), $this->report());
        $this->assertEqualsWithDelta(31.0, $this->load($system), 1e-9);
    }

    public function testWithoutAProbeThereIsNoLogbook(): void
    {
        [$fleetId, $system] = $this->expedition([203 => 5]);

        app(Expedition::class)->expeditionMission($this->fleetRow($fleetId));

        $this->assertStringNotContainsString(__('game/expedition.exp_status_fresh_1'), $this->report());
        $this->assertStringNotContainsString(__('game/expedition.exp_status_fresh_2'), $this->report());
        $this->assertEqualsWithDelta(1.0, $this->load($system), 1e-9);
    }

    private function load(int $system): float
    {
        $load = DB::table('expedition_areas')->where('system', $system)->value('load');
        $this->assertIsNumeric($load);

        return (float) $load;
    }

    private function report(): string
    {
        $text = DB::table('messages')->where('message_receiver', $this->player->id)->orderByDesc('message_id')->value('message_text');
        $this->assertIsString($text);

        return $text;
    }

    /**
     * @param array<int, int> $ships
     *
     * @return array{0: int, 1: int, 2: int} fleet id, system, end of the stay
     */
    private function expedition(array $ships): array
    {
        $home = DB::table('planets')->where('planet_user_id', $this->player->id)->first(['planet_galaxy', 'planet_system', 'planet_planet']);
        $this->assertNotNull($home);
        $endStay = time() - 30;

        $fleetId = DB::table('fleets')->insertGetId([
            'fleet_owner' => $this->player->id, 'fleet_mission' => 15, 'fleet_amount' => array_sum($ships), 'fleet_array' => FleetShips::encode($ships),
            'fleet_start_time' => $endStay - 3600, 'fleet_end_stay' => $endStay, 'fleet_end_time' => time() + 600,
            'fleet_start_galaxy' => $home->planet_galaxy, 'fleet_start_system' => $home->planet_system, 'fleet_start_planet' => $home->planet_planet, 'fleet_start_type' => 1,
            'fleet_end_galaxy' => 1, 'fleet_end_system' => $home->planet_system, 'fleet_end_planet' => 16, 'fleet_end_type' => 1,
            'fleet_mess' => 0,
        ], 'fleet_id');

        return [$fleetId, (int) $home->planet_system, $endStay];
    }

    /**
     * @return array<string, mixed>
     */
    private function fleetRow(int $fleetId): array
    {
        return (array) DB::table('fleets')->where('fleet_id', $fleetId)->first() + ['research_hyperspace_technology' => 0];
    }
}
