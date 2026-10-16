<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Items;

use App\Services\Game\Items\Item;
use App\Services\Game\Items\ItemCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The items of the original game with their values and shop prices.
 */
#[CoversClass(ItemCatalog::class)]
#[CoversClass(Item::class)]
class ItemCatalogTest extends TestCase
{
    public function testTheItemsOfTheOriginal(): void
    {
        $catalog = new ItemCatalog();

        // 3 boosters, planet and moon fields, kraken, detroid, newtron; 3 tiers each
        $this->assertCount(24, $catalog->all());

        $booster = $catalog->get('crystal_booster_silver');
        $this->assertNotNull($booster);
        $this->assertSame([Item::BOOSTER, 20, 7 * 86400, 8500, 'crystal'], [$booster->type, $booster->value, $booster->duration, $booster->price, $booster->resource]);

        $this->assertSame([15, 300000], [$catalog->get('planet_fields_gold')?->value, $catalog->get('planet_fields_gold')?->price]);
        $this->assertSame([2, 30000], [$catalog->get('moon_fields_bronze')?->value, $catalog->get('moon_fields_bronze')?->price]);
        $this->assertSame([1800, 7200, 21600], [$catalog->get('kraken_bronze')?->value, $catalog->get('detroid_silver')?->value, $catalog->get('newtron_gold')?->value]);
        $this->assertNull($catalog->get('kraken_gold')?->price);
    }

    public function testTheShopSellsBoostersAndFieldsTheOthersHandOutTheRest(): void
    {
        $catalog = new ItemCatalog();

        $this->assertCount(15, $catalog->shop());
        $this->assertArrayNotHasKey('kraken_bronze', $catalog->shop());

        $this->assertCount(18, $catalog->tradable());
        $this->assertArrayNotHasKey('planet_fields_bronze', $catalog->tradable());
        $this->assertCount(6, $catalog->tradable('gold'));
        $this->assertSame('it_metal_booster', $catalog->get('metal_booster_gold')?->nameKey());
        $this->assertSame('it_newtron', $catalog->get('newtron_gold')?->nameKey());
    }
}
