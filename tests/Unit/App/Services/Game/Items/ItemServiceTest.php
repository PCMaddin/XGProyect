<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Items;

use App\Models\BuildingQueue;
use App\Models\ResearchQueue;
use App\Services\Game\Items\ItemCatalog;
use App\Services\Game\Items\ItemService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Inventory, shop and the effects of the items.
 */
#[CoversClass(ItemService::class)]
class ItemServiceTest extends DatabaseTestCase
{
    private const USER = 7;
    private const PLANET = 70;
    private const MOON = 71;
    private const NOW = 1000000;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->insert(['id' => self::USER, 'name' => 'Player', 'email' => 'player@example.com', 'password' => 'x', 'home_planet_id' => self::PLANET, 'current_planet' => self::PLANET]);
        DB::table('premium')->insert(['premium_user_id' => self::USER, 'premium_dark_matter' => 10000]);
        DB::table('planets')->insert([
            ['planet_id' => self::PLANET, 'planet_user_id' => self::USER, 'planet_type' => 1, 'planet_field_max' => 163, 'planet_b_hangar_id' => ''],
            ['planet_id' => self::MOON, 'planet_user_id' => self::USER, 'planet_type' => 3, 'planet_field_max' => 1, 'planet_b_hangar_id' => ''],
        ]);
    }

    public function testBuyingCostsDarkMatter(): void
    {
        $this->assertNull($this->items()->buy(self::USER, 'metal_booster_silver'));
        $this->assertSame(['metal_booster_silver' => 1], $this->items()->inventory(self::USER));
        $this->assertSame(1500, $this->darkMatter());

        $this->assertSame('it_no_enough_dark_matter', $this->items()->buy(self::USER, 'metal_booster_bronze'));
        // not sold in the shop
        $this->assertSame('it_unknown_item', $this->items()->buy(self::USER, 'kraken_bronze'));
        $this->assertSame(1500, $this->darkMatter());
    }

    public function testABoosterWorksAWeekAndAnEqualOneExtendsIt(): void
    {
        $this->items()->add(self::USER, 'metal_booster_bronze', 2);
        $this->items()->add(self::USER, 'metal_booster_gold');

        $this->assertNull($this->items()->use(self::USER, self::PLANET, 'metal_booster_bronze', self::NOW));
        $this->assertNull($this->items()->use(self::USER, self::PLANET, 'metal_booster_bronze', self::NOW + 100));
        $booster = $this->items()->activeBoosters(self::PLANET, self::NOW)['metal'];
        $this->assertSame([10, self::NOW, self::NOW + 14 * 86400], [$booster->percent, $booster->starts_at, $booster->ends_at]);

        // another percent only when it ran out, never on a moon
        $this->assertSame('it_other_booster_active', $this->items()->use(self::USER, self::PLANET, 'metal_booster_gold', self::NOW));
        $this->assertSame('it_planet_only', $this->items()->use(self::USER, self::MOON, 'metal_booster_gold', self::NOW));
        $this->assertNull($this->items()->use(self::USER, self::PLANET, 'metal_booster_gold', self::NOW + 14 * 86400));
        $this->assertSame(30, $this->items()->activeBoosters(self::PLANET, self::NOW + 14 * 86400)['metal']->percent);

        $this->assertSame([], $this->items()->inventory(self::USER));
        $this->assertSame('it_not_owned', $this->items()->use(self::USER, self::PLANET, 'metal_booster_bronze', self::NOW));
    }

    public function testBoosterSharesFollowTheTimeTheyWorked(): void
    {
        DB::table('planet_boosters')->insert(['planet_id' => self::PLANET, 'resource' => 'crystal', 'percent' => 20, 'starts_at' => 1000, 'ends_at' => 2000]);

        $this->assertEqualsWithDelta(['crystal' => 0.2], $this->items()->boosterShares(self::PLANET, 1000, 2000), 1e-9);
        // worked half of the time
        $this->assertEqualsWithDelta(['crystal' => 0.1], $this->items()->boosterShares(self::PLANET, 1500, 2500), 1e-9);
        $this->assertSame([], $this->items()->boosterShares(self::PLANET, 2000, 3000));
    }

    public function testFieldItemsWorkOncePerPlanetOrMoon(): void
    {
        $this->items()->add(self::USER, 'planet_fields_silver', 2);
        $this->items()->add(self::USER, 'moon_fields_gold');

        $this->assertSame('it_moon_only', $this->items()->use(self::USER, self::PLANET, 'moon_fields_gold', self::NOW));
        $this->assertNull($this->items()->use(self::USER, self::PLANET, 'planet_fields_silver', self::NOW));
        $this->assertSame('it_fields_used', $this->items()->use(self::USER, self::PLANET, 'planet_fields_silver', self::NOW));
        $this->assertNull($this->items()->use(self::USER, self::MOON, 'moon_fields_gold', self::NOW));

        $this->assertSame([172, 7], DB::table('planets')->orderBy('planet_id')->pluck('planet_field_max')->map(fn ($v): int => $this->int($v))->all());
        $this->assertSame(['planet_fields_silver' => 1], $this->items()->inventory(self::USER));
    }

    public function testKrakenShortensTheBuildingOrders(): void
    {
        $this->items()->add(self::USER, 'kraken_silver', 2);
        $this->assertSame('it_nothing_building', $this->items()->use(self::USER, self::PLANET, 'kraken_silver', self::NOW));

        $end = time() + 3 * 3600;
        BuildingQueue::create(['planet_id' => self::PLANET, 'position' => 1, 'building_id' => 1, 'target_level' => 1, 'mode' => 'build', 'duration' => 10800, 'end_time' => $end]);
        BuildingQueue::create(['planet_id' => self::PLANET, 'position' => 2, 'building_id' => 2, 'target_level' => 1, 'mode' => 'build', 'duration' => 3600, 'end_time' => $end + 3600]);

        $this->assertNull($this->items()->use(self::USER, self::PLANET, 'kraken_silver', self::NOW));
        $this->assertSame([$end - 7200, $end - 3600], BuildingQueue::orderBy('position')->pluck('end_time')->map(fn ($v): int => $this->int($v))->all());
        $this->assertSame($end - 7200, $this->int(DB::table('planets')->where('planet_id', self::PLANET)->value('planet_b_building')));

        // never earlier than now: the second kraken takes the rest of the hour
        $this->assertNull($this->items()->use(self::USER, self::PLANET, 'kraken_silver', self::NOW));
        $first = $this->int(BuildingQueue::where('position', 1)->value('end_time'));
        $this->assertLessThanOrEqual(time(), $first);
        $this->assertGreaterThanOrEqual(time() - 2, $first);
    }

    public function testDetroidAddsShipyardTime(): void
    {
        $this->items()->add(self::USER, 'detroid_bronze', 2);
        $this->assertSame('it_nothing_in_shipyard', $this->items()->use(self::USER, self::PLANET, 'detroid_bronze', self::NOW));

        DB::table('planets')->where('planet_id', self::PLANET)->update(['planet_b_hangar_id' => '204,10;', 'planet_b_hangar' => 50]);
        $this->assertNull($this->items()->use(self::USER, self::PLANET, 'detroid_bronze', self::NOW));
        $this->assertSame(1850, $this->int(DB::table('planets')->where('planet_id', self::PLANET)->value('planet_b_hangar')));
    }

    public function testNewtronShortensTheResearch(): void
    {
        $this->items()->add(self::USER, 'newtron_gold');
        DB::table('research')->insert(['research_user_id' => self::USER]);
        $this->assertSame('it_nothing_researching', $this->items()->use(self::USER, self::PLANET, 'newtron_gold', self::NOW));

        $end = time() + 86400;
        ResearchQueue::create(['user_id' => self::USER, 'planet_id' => self::PLANET, 'position' => 1, 'tech_id' => 113, 'target_level' => 1, 'duration' => 86400, 'end_time' => $end]);

        $this->assertNull($this->items()->use(self::USER, self::PLANET, 'newtron_gold', self::NOW));
        $this->assertSame($end - 21600, $this->int(ResearchQueue::value('end_time')));
        $this->assertSame($end - 21600, $this->int(DB::table('planets')->where('planet_id', self::PLANET)->value('planet_b_tech')));
    }

    public function testMoonsFiresAMoonShotAtAPlanetWithoutAMoon(): void
    {
        DB::table('planets')->insert(['planet_id' => 72, 'planet_user_id' => self::USER, 'planet_type' => 1, 'planet_galaxy' => 1, 'planet_system' => 2, 'planet_planet' => 3, 'planet_b_hangar_id' => '']);
        $this->items()->add(self::USER, 'moons_gold', 100);
        $service = $this->items();

        // planet 70 already has its moon 71
        $this->assertSame('it_moon_exists', $service->use(self::USER, self::PLANET, 'moons_gold', self::NOW));
        $this->assertSame('it_planet_only', $service->use(self::USER, self::MOON, 'moons_gold', self::NOW));

        mt_srand(7);
        $outcomes = [];

        do {
            $this->assertNull($service->use(self::USER, 72, 'moons_gold', self::NOW));
            $outcomes[] = $service->outcome();
        } while ($service->outcome() === 'it_moons_failed' && count($outcomes) < 100);

        // used up on every try, a moon at 20 % (8.000 to 10.000 km, as in a battle)
        $this->assertSame('it_moons_success', end($outcomes));
        $this->assertSame(['moons_gold' => 100 - count($outcomes)], $service->inventory(self::USER));
        $moon = DB::table('planets')->where('planet_galaxy', 1)->where('planet_system', 2)->where('planet_planet', 3)->where('planet_type', 3)->first();
        $this->assertNotNull($moon);
        $this->assertGreaterThanOrEqual(4000, (int) $moon->planet_diameter);
        $this->assertLessThanOrEqual(10000, (int) $moon->planet_diameter);
        $this->assertSame('it_moon_exists', $service->use(self::USER, 72, 'moons_gold', self::NOW));
        // not sold by the auctioneer or found on expeditions
        $this->assertSame(50000, $this->catalog()->get('moons_gold')?->price);
        $this->assertArrayNotHasKey('moons_gold', $this->catalog()->tradable());
    }

    public function testOnlyOwnPlanets(): void
    {
        $this->items()->add(self::USER, 'metal_booster_bronze');
        DB::table('planets')->where('planet_id', self::PLANET)->update(['planet_user_id' => self::USER + 1]);

        $this->assertSame('it_unknown_item', $this->items()->use(self::USER, self::PLANET, 'metal_booster_bronze', self::NOW));
        $this->assertSame(['metal_booster_bronze' => 1], $this->items()->inventory(self::USER));
    }

    private function items(): ItemService
    {
        return app(ItemService::class);
    }

    private function darkMatter(): int
    {
        $value = DB::table('premium')->where('premium_user_id', self::USER)->value('premium_dark_matter');

        return is_numeric($value) ? (int) $value : -1;
    }

    private function int(mixed $value): int
    {
        $this->assertIsNumeric($value);

        return (int) $value;
    }

    private function catalog(): ItemCatalog
    {
        return new ItemCatalog();
    }
}
