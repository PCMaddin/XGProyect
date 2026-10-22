<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Services\Game\ImportExportService;
use App\Services\Game\Items\ItemCatalog;
use App\Services\Game\Items\ItemService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Import/export as in the original: one container a day, the same tier and
 * price for everybody, paid in one resource at 1 : 1,5 : 3, swapped at most
 * twice for dark matter, bought once a day.
 */
#[CoversClass(ImportExportService::class)]
class ImportExportServiceTest extends DatabaseTestCase
{
    private const USER = 7;
    private const PLANET = 70;
    private const DAY = '2026-10-16';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->insert(['id' => self::USER, 'name' => 'Player', 'email' => 'player@example.com', 'password' => 'x', 'home_planet_id' => self::PLANET, 'current_planet' => self::PLANET]);
        DB::table('premium')->insert(['premium_user_id' => self::USER, 'premium_dark_matter' => 10000]);
        DB::table('planets')->insert(['planet_id' => self::PLANET, 'planet_user_id' => self::USER, 'planet_metal' => 1000000, 'planet_crystal' => 0, 'planet_deuterium' => 70000]);
    }

    public function testTheContainerOfTheDayIsTheSameForEverybody(): void
    {
        $service = $this->service();
        $ranges = ['bronze' => [40000, 50000], 'silver' => [60000, 100000], 'gold' => [100000, 200000]];
        $tiers = [];

        for ($day = 1; $day <= 200; $day++) {
            $date = date('Y-m-d', 1767225600 + ($day - 1) * 86400);
            $container = $service->container($date);

            $this->assertSame($container, $service->container($date));
            $this->assertGreaterThanOrEqual($ranges[$container['tier']][0], $container['price']);
            $this->assertLessThanOrEqual($ranges[$container['tier']][1], $container['price']);
            $tiers[$container['tier']] = ($tiers[$container['tier']] ?? 0) + 1;
        }

        // bronze is the most common, gold the rarest
        $this->assertGreaterThan($tiers['silver'] ?? 0, $tiers['bronze'] ?? 0);
        $this->assertGreaterThan($tiers['gold'] ?? 0, $tiers['silver'] ?? 0);
    }

    public function testThePriceIsPaidInOneResource(): void
    {
        $this->assertSame(60000, $this->service()->cost(60000, 'metal'));
        $this->assertSame(40000, $this->service()->cost(60000, 'crystal'));
        $this->assertSame(20000, $this->service()->cost(60000, 'deuterium'));
    }

    public function testBuyingPutsTheItemIntoTheInventoryOnceADay(): void
    {
        $container = $this->service()->container(self::DAY);
        $deuterium = $this->service()->cost($container['price'], 'deuterium');

        $this->assertSame('tr_ie_not_enough', $this->service()->buy(self::USER, self::PLANET, 'crystal', self::DAY));
        $this->assertNull($this->service()->buy(self::USER, self::PLANET, 'deuterium', self::DAY));
        $this->assertSame('tr_ie_already_bought', $this->service()->buy(self::USER, self::PLANET, 'metal', self::DAY));

        $state = $this->service()->state(self::USER, self::DAY);
        $this->assertTrue($state['bought']);
        $this->assertSame([$state['item'] => 1], app(ItemService::class)->inventory(self::USER));
        $this->assertArrayHasKey($state['item'], app(ItemCatalog::class)->tradable($container['tier']));
        $this->assertSame(70000 - $deuterium, (int) $this->value('planets', 'planet_deuterium'));

        // the next day there is a new one
        $this->assertFalse($this->service()->state(self::USER, '2026-10-17')['bought']);
        $this->assertSame(1, DB::table('import_exports')->count());
    }

    public function testSwappingCostsDarkMatterTwiceAtMost(): void
    {
        $tier = $this->service()->container(self::DAY)['tier'];
        $price = $this->service()->swapPrice($tier);
        $this->assertSame(['bronze' => 500, 'silver' => 1500, 'gold' => 4500][$tier], $price);

        $this->assertNull($this->service()->swap(self::USER, self::DAY));
        $this->assertNull($this->service()->swap(self::USER, self::DAY));
        $this->assertSame('tr_ie_no_swaps', $this->service()->swap(self::USER, self::DAY));

        $state = $this->service()->state(self::USER, self::DAY);
        $this->assertSame(2, $state['swaps']);
        $this->assertArrayHasKey($state['item'], app(ItemCatalog::class)->tradable($tier));
        $this->assertSame(10000 - 2 * $price, (int) $this->value('premium', 'premium_dark_matter'));
    }

    private function service(): ImportExportService
    {
        return app(ImportExportService::class);
    }

    private function value(string $table, string $column): float
    {
        $value = DB::table($table)->value($column);
        $this->assertIsNumeric($value);

        return (float) $value;
    }
}
