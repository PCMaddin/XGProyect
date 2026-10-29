<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Expedition;
use App\Models\Fleets;
use App\Services\Game\Formulas\ExpeditionService;
use App\Services\Game\Items\ItemCatalog;
use App\Services\Game\Items\ItemService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\DatabaseTestCase;

/**
 * The expedition result "item": a bronze item in a normal find, silver in
 * a large one, two gold items in a huge one; they go to the inventory, the
 * report names them, the fleet returns.
 */
#[CoversClass(Expedition::class)]
class ExpeditionItemTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function sizes(): array
    {
        return [
            'normal find' => ['normal', 'bronze', 1],
            'large find' => ['large', 'silver', 1],
            'huge find' => ['xl', 'gold', 2],
        ];
    }

    #[DataProvider('sizes')]
    public function testTheFleetFindsItems(string $size, string $tier, int $count): void
    {
        $this->app->instance(ExpeditionService::class, new class (app(SettingsService::class), $size) extends ExpeditionService {
            public function __construct(SettingsService $settings, private string $size)
            {
                parent::__construct($settings);
            }

            public function calculateResourceSourceSize(): string
            {
                return $this->size;
            }
        });

        DB::table('users')->insert(['id' => 5, 'name' => 'Explorer', 'email' => 'e@example.com', 'password' => 'x', 'home_planet_id' => 1, 'current_planet' => 1]);
        $fleetId = DB::table('fleets')->insertGetId(['fleet_owner' => 5, 'fleet_mission' => 15, 'fleet_mess' => 0, 'fleet_end_galaxy' => 1, 'fleet_end_system' => 2, 'fleet_end_planet' => 16], 'fleet_id');
        $fleet = (array) DB::table('fleets')->where('fleet_id', $fleetId)->first();

        (new ReflectionMethod(Expedition::class, 'resultItem'))->invoke(app(Expedition::class), $fleet);

        $inventory = app(ItemService::class)->inventory(5);
        $this->assertSame($count, array_sum($inventory));
        $this->assertSame([], array_diff_key($inventory, app(ItemCatalog::class)->tradable($tier)));
        $this->assertSame(1, Fleets::findOrFail($fleetId)->fleet_mess);

        $report = DB::table('messages')->where('message_receiver', 5)->value('message_text');
        $this->assertIsString($report);
        $this->assertStringContainsString(__('game/items.it_' . $tier), $report);
    }
}
