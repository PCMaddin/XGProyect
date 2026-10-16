<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Core\Enumerators\BuildingsEnumerator;
use App\Models\Options;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\Game\WreckFieldService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Wreck fields and the space dock: when a field forms, what lies in it, the
 * repair share by level, the repair time, putting the ships back into
 * service, burning up.
 */
#[CoversClass(WreckFieldService::class)]
class WreckFieldServiceTest extends DatabaseTestCase
{
    private const NOW = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        Options::insert(['name' => 'fleet_cdr', 'value' => '30', 'type' => 'int']);
        Options::insert(['name' => 'game_speed', 'value' => '2500', 'type' => 'int']);
        DB::table('planets')->insert([
            ['planet_id' => 70, 'planet_user_id' => 7, 'planet_type' => 1, 'planet_galaxy' => 1, 'planet_system' => 2, 'planet_planet' => 3],
            ['planet_id' => 71, 'planet_user_id' => 7, 'planet_type' => 3, 'planet_galaxy' => 1, 'planet_system' => 2, 'planet_planet' => 3],
        ]);
        DB::table('buildings')->insert([['building_planet_id' => 70], ['building_planet_id' => 71]]);
        DB::table('ships')->insert([['ship_planet_id' => 70, 'ship_battleship' => 2], ['ship_planet_id' => 71, 'ship_battleship' => 0]]);
    }

    public function testTheSpaceDockCostsFiveTimesMoreEachLevelAndItsEnergyTwoAndAHalf(): void
    {
        $developments = app(DevelopmentsService::class);

        $this->assertEquals(['metal' => 200, 'deuterium' => 50, 'energy_max' => 50], $developments->developmentPrice(BuildingsEnumerator::BUILDING_SPACE_DOCK, 0));
        $this->assertEquals(['metal' => 5000, 'deuterium' => 1250, 'energy_max' => 313], $developments->developmentPrice(BuildingsEnumerator::BUILDING_SPACE_DOCK, 2));
        $this->assertEquals(['metal' => 25000, 'deuterium' => 6250, 'energy_max' => 781], $developments->developmentPrice(BuildingsEnumerator::BUILDING_SPACE_DOCK, 3));
    }

    public function testTheRepairShareGrowsWithTheLevel(): void
    {
        $wrecks = app(WreckFieldService::class);

        $this->assertSame([0.0, 0.45, 0.48, 0.5, 0.54, 0.56, 0.56], array_map($wrecks->repairShare(...), [0, 1, 2, 4, 10, 14, 20]));
        // 31,5 % and 39,2 % of the destroyed ships in a 30 % universe
        $this->assertEqualsWithDelta(0.315, 0.7 * $wrecks->repairShare(1), 1e-9);
        $this->assertEqualsWithDelta(0.392, 0.7 * $wrecks->repairShare(15), 1e-9);
    }

    public function testAFieldNeeds150000UnitsAndFivePercentOfTheFleet(): void
    {
        $wrecks = app(WreckFieldService::class);

        // 2 battleships: 120.000 units
        $this->assertFalse($wrecks->recordBattle(70, 7, [207 => 10], [207 => 2], self::NOW));
        // 3 battleships, but not 5 % of 100
        $this->assertFalse($wrecks->recordBattle(70, 7, [207 => 100], [207 => 3], self::NOW));
        // solar satellites leave no wrecks
        $this->assertFalse($wrecks->recordBattle(70, 7, [212 => 200, 207 => 1], [212 => 200, 207 => 1], self::NOW));
        $this->assertNull($wrecks->field(70));

        // 10 battleships and 3 cruisers: 70 % of them lie in the field
        $this->assertTrue($wrecks->recordBattle(70, 7, [207 => 20, 206 => 3, 401 => 50], [207 => 10, 206 => 3, 401 => 50], self::NOW));
        $field = $wrecks->field(70);
        $this->assertNotNull($field);
        $this->assertSame([207 => 7, 206 => 2], $field['ships']);
        $this->assertSame(self::NOW + 3 * 86400, $field['expires_at']);
    }

    public function testAnotherBattleAddsToTheFieldAndAMoonBattleLeavesItAtThePlanet(): void
    {
        $wrecks = app(WreckFieldService::class);

        $wrecks->recordBattle(70, 7, [207 => 10], [207 => 10], self::NOW);
        $this->assertTrue($wrecks->recordBattle(71, 7, [207 => 10], [207 => 10], self::NOW + 3600));

        $field = $wrecks->field(70);
        $this->assertNotNull($field);
        $this->assertSame([207 => 14], $field['ships']);
        $this->assertSame(self::NOW + 3600 + 3 * 86400, $field['expires_at']);
        $this->assertNull($wrecks->field(71));
    }

    public function testTheSpaceDockRepairsAndThePlayerPutsTheShipsIntoService(): void
    {
        $wrecks = app(WreckFieldService::class);
        $wrecks->recordBattle(70, 7, [207 => 20], [207 => 20], self::NOW);

        $this->assertSame('sd_no_space_dock', $wrecks->startRepair(7, 70, self::NOW));
        $this->assertSame('sd_no_wreck_field', $wrecks->startRepair(8, 70, self::NOW));

        DB::table('buildings')->where('building_planet_id', 70)->update(['building_space_dock' => 1, 'building_hangar' => 12]);
        $this->assertNull($wrecks->startRepair(7, 70, self::NOW));
        $this->assertSame('sd_repair_running', $wrecks->startRepair(7, 70, self::NOW));

        // 45 % of 14 battleships
        $field = $wrecks->field(70);
        $this->assertNotNull($field);
        $this->assertSame([207 => 6], $field['repaired']);
        $this->assertSame(self::NOW + $wrecks->repairTime([207 => 6], 12, 0), $field['repair_ends_at']);

        // a repair in progress keeps its ships, new wrecks are lost
        $this->assertFalse($wrecks->recordBattle(70, 7, [207 => 10], [207 => 10], self::NOW + 60));

        $this->assertSame('sd_repair_running', $wrecks->deploy(7, 70, self::NOW + 60));
        $this->assertNull($wrecks->deploy(7, 70, $field['repair_ends_at']));
        $this->assertSame(8, $this->battleships());
        $this->assertNull($wrecks->field(70));
    }

    public function testAfter30MinutesTheShipsRepairedSoFarGoIntoService(): void
    {
        $wrecks = app(WreckFieldService::class);
        DB::table('buildings')->where('building_planet_id', 70)->update(['building_space_dock' => 14]);
        // 14 battleships and 7 cruisers in the field: 7 and 3 are repaired, in 12 hours
        $wrecks->recordBattle(70, 7, [207 => 20, 206 => 10], [207 => 20, 206 => 10], self::NOW);
        $this->assertNull($wrecks->startRepair(7, 70, self::NOW));
        $field = $wrecks->field(70);
        $this->assertNotNull($field);
        $this->assertSame([207 => 7, 206 => 3], $field['repaired']);
        $ends = $field['repair_ends_at'];
        $half = self::NOW + intdiv($ends - self::NOW, 2);

        $this->assertFalse($wrecks->canDeployEarly($field, self::NOW + 1799));
        $this->assertSame('sd_repair_running', $wrecks->deploy(7, 70, self::NOW + 1799));
        $this->assertTrue($wrecks->canDeployEarly($field, $half));
        $this->assertSame([207 => 3, 206 => 1], $wrecks->repairedSoFar($field, $half));
        $this->assertSame([207 => 7, 206 => 3], $wrecks->repairedSoFar($field, $ends));

        // half way: the ships repaired so far, the rest is lost
        $this->assertNull($wrecks->deploy(7, 70, $half));
        $this->assertSame(2 + 3, $this->battleships());
        $this->assertNull($wrecks->field(70));
    }

    public function testTheRepairTakesTheShipyardsTimeUpTo12Hours(): void
    {
        $wrecks = app(WreckFieldService::class);
        $battleship = app(DevelopmentsService::class)->developmentTime(207, 0, 2, 0, 0, 0, false);

        $this->assertSame(min(43200, max(1800, 3 * $battleship)), $wrecks->repairTime([207 => 3], 2, 0));
        $this->assertSame(12 * 3600, $wrecks->repairTime([207 => 100000], 2, 0));
        $this->assertSame(1800, $wrecks->repairTime([204 => 1], 12, 5));
    }

    public function testFieldsBurnUpAndRepairedShipsGoIntoServiceOnTheirOwn(): void
    {
        $wrecks = app(WreckFieldService::class);
        $wrecks->recordBattle(70, 7, [207 => 20], [207 => 20], self::NOW);

        $wrecks->settle(70, self::NOW + 3 * 86400 - 1);
        $this->assertNotNull($wrecks->field(70));
        $wrecks->settle(70, self::NOW + 3 * 86400);
        $this->assertNull($wrecks->field(70));

        DB::table('buildings')->where('building_planet_id', 70)->update(['building_space_dock' => 14]);
        $wrecks->recordBattle(70, 7, [207 => 20], [207 => 20], self::NOW);
        $this->assertNull($wrecks->startRepair(7, 70, self::NOW + 10));
        $field = $wrecks->field(70);
        $this->assertNotNull($field);
        $ends = $field['repair_ends_at'];

        $wrecks->settle(70, $ends + 3 * 86400 - 1);
        $this->assertSame(2, $this->battleships());
        $wrecks->settle(70, $ends + 3 * 86400);
        // 56 % of 14
        $this->assertSame(2 + 7, $this->battleships());
        $this->assertNull($wrecks->field(70));
    }

    public function testAnAttackersWrecksWaitForTheFleet(): void
    {
        $wrecks = app(WreckFieldService::class);
        DB::table('fleets')->insert([
            'fleet_id' => 5, 'fleet_owner' => 7, 'fleet_mission' => 1, 'fleet_end_time' => self::NOW + 7200,
            'fleet_start_galaxy' => 1, 'fleet_start_system' => 2, 'fleet_start_planet' => 3, 'fleet_start_type' => 3,
        ]);
        DB::table('buildings')->where('building_planet_id', 70)->update(['building_space_dock' => 1]);

        $origins = $wrecks->fleetOrigins([5, 6]);
        $this->assertSame([5 => ['planet' => 70, 'user' => 7, 'back' => self::NOW + 7200]], $origins);

        $wrecks->recordBattle(70, 7, [207 => 20], [207 => 20], self::NOW, self::NOW + 7200);
        $this->assertSame('sd_fleet_not_back', $wrecks->startRepair(7, 70, self::NOW + 7199));
        $this->assertNull($wrecks->startRepair(7, 70, self::NOW + 7200));
    }

    private function battleships(): int
    {
        $value = DB::table('ships')->where('ship_planet_id', 70)->value('ship_battleship');
        $this->assertIsNumeric($value);

        return (int) $value;
    }
}
