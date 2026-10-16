<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Expedition;
use App\Services\Game\Formulas\ExpeditionService;
use App\Services\SettingsService;
use App\Support\FleetShips;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\DatabaseTestCase;

/**
 * A pathfinder in the expedition fleet doubles the resources found
 * (OGame 7).
 */
#[CoversClass(Expedition::class)]
class ExpeditionPathfinderTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{0: array<int, int>, 1: int}>
     */
    public static function fleets(): array
    {
        return [
            'without a pathfinder' => [[203 => 10], 1000],
            'with a pathfinder' => [[203 => 10, 219 => 1], 2000],
        ];
    }

    /**
     * @param array<int, int> $ships
     */
    #[DataProvider('fleets')]
    public function testAPathfinderDoublesTheResourcesFound(array $ships, int $metal): void
    {
        $this->app->instance(ExpeditionService::class, new class (app(SettingsService::class)) extends ExpeditionService {
            public function calculateResourceTypeObtained(): string
            {
                return 'metal';
            }

            public function calculateResourceSourceSize(): string
            {
                return 'normal';
            }

            public function getResourceSourceSizeMultChances(string $discoveryType): int
            {
                return 50;
            }

            public function getResourceFoundAmount(int $chancesMultiplier, int $expeditionPoints, string $resourceType): int
            {
                return 1000;
            }
        });

        DB::table('users')->insert(['id' => 5, 'name' => 'Explorer', 'email' => 'e@example.com', 'password' => 'x', 'home_planet_id' => 1, 'current_planet' => 1]);
        $fleetId = DB::table('fleets')->insertGetId([
            'fleet_owner' => 5, 'fleet_mission' => 15, 'fleet_mess' => 0, 'fleet_array' => FleetShips::encode($ships), 'fleet_amount' => array_sum($ships),
            'fleet_end_galaxy' => 1, 'fleet_end_system' => 2, 'fleet_end_planet' => 16,
        ], 'fleet_id');
        $fleet = (array) DB::table('fleets')->where('fleet_id', $fleetId)->first() + ['research_hyperspace_technology' => 0];

        $expedition = app(Expedition::class);
        (new ReflectionMethod(Expedition::class, 'setExpeditionPoints'))->invoke($expedition, $fleet);
        (new ReflectionMethod(Expedition::class, 'resultResources'))->invoke($expedition, $fleet);

        $loaded = DB::table('fleets')->where('fleet_id', $fleetId)->value('fleet_resource_metal');
        $this->assertIsNumeric($loaded);
        $this->assertSame($metal, (int) $loaded);
    }
}
