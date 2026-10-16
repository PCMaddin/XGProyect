<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Models\Options;
use App\Services\Game\ScrapMerchantService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The scrap merchant, as in OGame: he buys ships and defense systems of the
 * planet for 35 % of their price, as much as fits into the storages;
 * bargaining raises the share by 5 to 14 points up to 75 % and costs 2.000
 * dark matter more each time (at most 10.000); a sale resets both.
 */
#[CoversClass(ScrapMerchantService::class)]
class ScrapMerchantServiceTest extends DatabaseTestCase
{
    private const USER = 7;

    private const PLANET = 70;

    protected function setUp(): void
    {
        parent::setUp();

        $options = ['merchant_scrap_base_rate' => '35', 'merchant_scrap_max_rate' => '75', 'merchant_scrap_bargain_price' => '2000'];

        foreach ($options as $name => $value) {
            Options::insert(['name' => $name, 'value' => $value, 'type' => 'int']);
        }

        DB::table('premium')->insert(['premium_user_id' => self::USER, 'premium_dark_matter' => 5000]);
        DB::table('planets')->insert(['planet_id' => self::PLANET, 'planet_user_id' => self::USER, 'planet_metal' => 100, 'planet_crystal' => 0, 'planet_deuterium' => 0]);
        DB::table('ships')->insert(['ship_planet_id' => self::PLANET, 'ship_cruiser' => 10, 'ship_small_cargo_ship' => 1]);
        DB::table('defenses')->insert(['defense_planet_id' => self::PLANET, 'defense_rocket_launcher' => 4, 'defense_interplanetary_missile' => 5]);
        // level 5 storages hold 255.000 of each resource
        DB::table('buildings')->insert(['building_planet_id' => self::PLANET, 'building_metal_store' => 5, 'building_crystal_store' => 5, 'building_deuterium_tank' => 5]);
    }

    public function testHeBuysShipsAndDefensesButNoMissiles(): void
    {
        $units = $this->merchant()->units();

        $this->assertArrayHasKey(202, $units);
        $this->assertArrayHasKey(215, $units);
        $this->assertArrayHasKey(401, $units);
        $this->assertArrayHasKey(408, $units);
        $this->assertArrayNotHasKey(502, $units);
        $this->assertArrayNotHasKey(503, $units);
    }

    public function testHePaysTheShareOfThePrice(): void
    {
        // cruiser 20000/7000/2000, rocket launcher 2000/0/0
        $this->assertSame(
            ['metal' => 16100, 'crystal' => 4900, 'deuterium' => 1400],
            $this->merchant()->payment([206 => 2, 401 => 3, 503 => 5], 35)
        );
    }

    public function testASaleRemovesTheUnitsPaysAndResetsTheRate(): void
    {
        DB::table('premium')->where('premium_user_id', self::USER)->update(['premium_scrap_rate' => 50, 'premium_scrap_bargains' => 3]);

        $result = $this->merchant()->scrap(self::USER, self::PLANET, [206 => 3, 401 => 4, 202 => 0, 503 => 5]);

        $this->assertSame(['payment' => ['metal' => 34000, 'crystal' => 10500, 'deuterium' => 3000]], $result);
        $this->assertSame([7, 1], $this->ships());
        $this->assertSame([0, 5], $this->defenses());
        $this->assertSame([34100, 10500, 3000], $this->resources());
        $this->assertSame(35, $this->merchant()->rate(self::USER));
        $this->assertSame(2000, $this->merchant()->bargainPrice(self::USER));
    }

    public function testHePaysOnlyWhatFitsIntoTheStorages(): void
    {
        DB::table('planets')->where('planet_id', self::PLANET)->update(['planet_metal' => 250000]);

        // 3 cruisers bring 21.000 metal, 5.000 fit
        $this->assertSame(['error' => ['tr_no_enough_storage_for', 'metal']], $this->merchant()->scrap(self::USER, self::PLANET, [206 => 3]));
        $this->assertSame([10, 1], $this->ships());

        // 1 cruiser brings 7.000 metal: still too much; a small cargo 700
        $this->assertSame(['error' => ['tr_no_enough_storage_for', 'metal']], $this->merchant()->scrap(self::USER, self::PLANET, [206 => 1]));
        $this->assertArrayHasKey('payment', $this->merchant()->scrap(self::USER, self::PLANET, [202 => 1]));
        $this->assertSame([250700, 700, 0], $this->resources());
    }

    public function testNothingChangesWhenTheUnitsAreNotThere(): void
    {
        $this->assertSame(['error' => ['tr_scrap_not_enough', 'ship_cruiser']], $this->merchant()->scrap(self::USER, self::PLANET, [401 => 1, 206 => 11]));
        $this->assertSame(['error' => ['tr_scrap_nothing_selected', '']], $this->merchant()->scrap(self::USER, self::PLANET, [503 => 1, 206 => -2]));
        // another player's planet
        $this->assertSame(['error' => ['tr_scrap_nothing_selected', '']], $this->merchant()->scrap(self::USER + 1, self::PLANET, [206 => 1]));

        $this->assertSame([10, 1], $this->ships());
        $this->assertSame([4, 5], $this->defenses());
        $this->assertSame([100, 0, 0], $this->resources());
    }

    public function testBargainingCostsMoreEachTimeAndStopsAtTheMaximum(): void
    {
        DB::table('premium')->where('premium_user_id', self::USER)->update(['premium_dark_matter' => 100000]);
        $this->assertSame(35, $this->merchant()->rate(self::USER));
        $this->assertSame(2000, $this->merchant()->bargainPrice(self::USER));

        $this->assertNull($this->merchant()->bargain(self::USER));
        $rate = $this->merchant()->rate(self::USER);
        $this->assertGreaterThanOrEqual(40, $rate);
        $this->assertLessThanOrEqual(49, $rate);
        $this->assertSame(98000, $this->darkMatter());
        $this->assertSame(4000, $this->merchant()->bargainPrice(self::USER));

        // the price grows by 2.000 a bargain, up to 10.000
        DB::table('premium')->where('premium_user_id', self::USER)->update(['premium_scrap_rate' => 40, 'premium_scrap_bargains' => 4]);
        $this->assertSame(10000, $this->merchant()->bargainPrice(self::USER));
        DB::table('premium')->where('premium_user_id', self::USER)->update(['premium_scrap_bargains' => 9]);
        $this->assertSame(10000, $this->merchant()->bargainPrice(self::USER));

        DB::table('premium')->where('premium_user_id', self::USER)->update(['premium_scrap_rate' => 70]);
        $this->assertNull($this->merchant()->bargain(self::USER));
        $this->assertSame(75, $this->merchant()->rate(self::USER));
        $this->assertSame('tr_scrap_final', $this->merchant()->bargain(self::USER));
        $this->assertSame(88000, $this->darkMatter());
    }

    public function testNoBargainWithoutEnoughDarkMatter(): void
    {
        DB::table('premium')->where('premium_user_id', self::USER)->update(['premium_dark_matter' => 1999]);

        $this->assertSame('tr_no_enough_dark_matter', $this->merchant()->bargain(self::USER));
        $this->assertSame(35, $this->merchant()->rate(self::USER));
        $this->assertSame(1999, $this->darkMatter());
    }

    private function merchant(): ScrapMerchantService
    {
        return app(ScrapMerchantService::class);
    }

    private function darkMatter(): int
    {
        $value = DB::table('premium')->where('premium_user_id', self::USER)->value('premium_dark_matter');

        return is_numeric($value) ? (int) $value : -1;
    }

    /**
     * @return list<int> cruisers, small cargo ships
     */
    private function ships(): array
    {
        $row = DB::table('ships')->where('ship_planet_id', self::PLANET)->first(['ship_cruiser', 'ship_small_cargo_ship']);
        $this->assertNotNull($row);

        return [(int) $row->ship_cruiser, (int) $row->ship_small_cargo_ship];
    }

    /**
     * @return list<int> rocket launchers, interplanetary missiles
     */
    private function defenses(): array
    {
        $row = DB::table('defenses')->where('defense_planet_id', self::PLANET)->first(['defense_rocket_launcher', 'defense_interplanetary_missile']);
        $this->assertNotNull($row);

        return [(int) $row->defense_rocket_launcher, (int) $row->defense_interplanetary_missile];
    }

    /**
     * @return list<int>
     */
    private function resources(): array
    {
        $planet = DB::table('planets')->where('planet_id', self::PLANET)->first(['planet_metal', 'planet_crystal', 'planet_deuterium']);
        $this->assertNotNull($planet);

        return [(int) $planet->planet_metal, (int) $planet->planet_crystal, (int) $planet->planet_deuterium];
    }
}
