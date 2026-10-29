<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Bots;

use App\Services\Game\Bots\BotTradeRules;
use App\Services\Game\Items\Item;
use App\Services\Game\Items\ItemCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The bots' trading rules: surplus and shortfall against the next steps,
 * a large surplus worth a day of production, the merchant only for a
 * shortfall the mines need more than 6 hours for,
 * up to twice the shortfall for half the surplus and what fits into the
 * storage; items worth their extra production; bids open at a quarter of
 * the limit and top the others by 10 % up to it.
 */
#[CoversClass(BotTradeRules::class)]
class BotTradeRulesTest extends TestCase
{
    private const PER_HOUR = ['metal' => 10000.0, 'crystal' => 5000.0, 'deuterium' => 2000.0];

    private BotTradeRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = new BotTradeRules();
    }

    public function testSurplusAndShortfallAgainstTheNextSteps(): void
    {
        $stock = ['metal' => 600000.0, 'crystal' => 10000.0, 'deuterium' => 5000.0];
        $need = ['metal' => 100000.0, 'crystal' => 80000.0];

        $this->assertSame([
            'surplus' => ['metal' => 500000.0, 'crystal' => 0.0, 'deuterium' => 5000.0],
            'shortfall' => ['metal' => 0.0, 'crystal' => 70000.0, 'deuterium' => 0.0],
        ], $this->rules->balance($stock, $need));
    }

    public function testTheSurplusResourceIsTheLargestInMetalUnitsIfLarge(): void
    {
        // a day of 10.000 metal, 5.000 crystal and 2.000 deuterium: 564.000 metal units
        $this->assertSame('metal', $this->rules->surplusResource(['metal' => 600000.0, 'crystal' => 150000.0, 'deuterium' => 0.0], self::PER_HOUR));
        // 400.000 crystal are 600.000 metal units
        $this->assertSame('crystal', $this->rules->surplusResource(['metal' => 590000.0, 'crystal' => 400000.0, 'deuterium' => 0.0], self::PER_HOUR));
        $this->assertNull($this->rules->surplusResource(['metal' => 563999.0, 'crystal' => 300000.0, 'deuterium' => 150000.0], self::PER_HOUR));
        // at least 50.000 metal units, whatever the planet produces
        $this->assertSame('metal', $this->rules->surplusResource(['metal' => 50000.0], ['metal' => 100.0]));
        $this->assertNull($this->rules->surplusResource(['metal' => 49999.0], []));
    }

    public function testTheMerchantBuysTheLargestSurplusIfItPaysTheWholeShortfall(): void
    {
        $this->assertSame('deuterium', $this->rules->sellResource(['metal' => 20000.0, 'crystal' => 10000.0, 'deuterium' => 7000.0]));
        $this->assertNull($this->rules->sellResource(['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0]));

        $this->assertTrue($this->rules->covers(['crystal' => 5000], ['metal' => 0.0, 'crystal' => 5000.0, 'deuterium' => 0.0], 'metal'));
        $this->assertFalse($this->rules->covers(['crystal' => 5000], ['metal' => 0.0, 'crystal' => 5000.0, 'deuterium' => 1.0], 'metal'));
        $this->assertFalse($this->rules->covers(['crystal' => 4999], ['crystal' => 5000.0], 'metal'));
    }

    public function testTheMerchantOnlyForAShortfallTheMinesNeedMoreThanSixHoursFor(): void
    {
        $this->assertFalse($this->rules->worthTrading(['metal' => 0.0, 'crystal' => 30000.0, 'deuterium' => 0.0], self::PER_HOUR));
        $this->assertTrue($this->rules->worthTrading(['metal' => 0.0, 'crystal' => 30001.0, 'deuterium' => 0.0], self::PER_HOUR));
        // a resource the planet does not produce
        $this->assertTrue($this->rules->worthTrading(['deuterium' => 1.0], ['deuterium' => 0.0]));
        $this->assertFalse($this->rules->worthTrading(['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0], []));
    }

    public function testThePurchaseIsTwiceTheShortfallForHalfTheSurplus(): void
    {
        $rates = ['metal' => 0.0, 'crystal' => 0.5, 'deuterium' => 0.25];
        $room = ['metal' => 0.0, 'crystal' => 1000000.0, 'deuterium' => 1000000.0];

        // 40.000 crystal for 80.000 metal of 500.000
        $this->assertSame(['crystal' => 40000], $this->rules->purchase('metal', 500000, ['crystal' => 20000.0], $room, $rates));
        // both: 200.000 crystal and 40.000 deuterium cost 560.000 metal, half the surplus is 250.000
        $this->assertSame(
            ['crystal' => 89285, 'deuterium' => 17857],
            $this->rules->purchase('metal', 500000, ['metal' => 0.0, 'crystal' => 100000.0, 'deuterium' => 20000.0], $room, $rates)
        );
        // no more than fits into the storage, one unit below it
        $this->assertSame(['crystal' => 9999], $this->rules->purchase('metal', 500000, ['crystal' => 20000.0], ['crystal' => 10000.5], $rates));
        // nothing he does not sell, nothing it does not lack
        $this->assertSame([], $this->rules->purchase('metal', 500000, ['metal' => 5000.0, 'crystal' => 0.0], $room, $rates));
        $this->assertSame([], $this->rules->purchase('metal', 0, ['crystal' => 5000.0], $room, $rates));
    }

    public function testAnItemIsWorthWhatItAddsOnThePlanet(): void
    {
        $catalog = new ItemCatalog();

        // 10 % of 10.000 metal for 168 hours
        $this->assertEqualsWithDelta(168000.0, $this->rules->worth([$this->item($catalog, 'metal_booster_bronze')], self::PER_HOUR), 0.01);
        // 30 % of 5.000 crystal for 168 hours, 1,5 metal units each
        $this->assertEqualsWithDelta(378000.0, $this->rules->worth([$this->item($catalog, 'crystal_booster_gold')], self::PER_HOUR), 0.01);
        // 6 hours of 10.000 + 7.500 + 6.000 metal units
        $this->assertEqualsWithDelta(141000.0, $this->rules->worth([$this->item($catalog, 'kraken_gold')], self::PER_HOUR), 0.01);
        $this->assertSame(0.0, $this->rules->worth([$this->item($catalog, 'planet_fields_bronze')], self::PER_HOUR));

        $this->assertEqualsWithDelta((168000.0 + 1800.0 / 3600 * 23500) / 2, $this->rules->worth(
            [$this->item($catalog, 'metal_booster_bronze'), $this->item($catalog, 'newtron_bronze')],
            self::PER_HOUR
        ), 0.01);
        $this->assertSame(0.0, $this->rules->worth([], self::PER_HOUR));
    }

    public function testAtMostAQuarterOfTheSurplusAndNeverAboveTheWorth(): void
    {
        $this->assertSame(100000.0, $this->rules->spendLimit('metal', 400000.0, 168000.0));
        $this->assertSame(50000.0, $this->rules->spendLimit('metal', 400000.0, 50000.0));
        // crystal counts 1,5, what it bid already counts in
        $this->assertSame(160000.0, $this->rules->spendLimit('crystal', 400000.0, 500000.0, 40000.0));
    }

    public function testABidOpensLowAndTopsTheOthersUpToTheLimit(): void
    {
        // opening: a quarter of the limit
        $this->assertSame(25000.0, $this->rules->bidTarget(null, 0.0, 100000.0, 7));
        // 10 % above the highest bid of another
        $this->assertSame(55000.0, $this->rules->bidTarget(['user_id' => 8, 'value' => 50000.0], 25000.0, 100000.0, 7));
        // at least 1.000 more
        $this->assertSame(3000.0, $this->rules->bidTarget(['user_id' => 8, 'value' => 2000.0], 0.0, 100000.0, 7));
        // up to the limit, then it gives up
        $this->assertSame(100000.0, $this->rules->bidTarget(['user_id' => 8, 'value' => 95000.0], 55000.0, 100000.0, 7));
        $this->assertNull($this->rules->bidTarget(['user_id' => 8, 'value' => 100000.0], 55000.0, 100000.0, 7));
        // leading, it waits
        $this->assertNull($this->rules->bidTarget(['user_id' => 7, 'value' => 25000.0], 25000.0, 100000.0, 7));
    }

    private function item(ItemCatalog $catalog, string $key): Item
    {
        $item = $catalog->get($key);
        $this->assertNotNull($item);

        return $item;
    }
}
