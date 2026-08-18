<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Game;

use App\Libraries\Game\ResourceMarket;
use App\Services\Game\Formulas\ProductionService;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ResourceMarket::class)]
class ResourceMarketTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $planet
     */
    private function market(array $user, array $planet): ResourceMarket
    {
        return new ResourceMarket($user, $planet, new ProductionService());
    }

    public function testStorageFullReflectsTheStoredAmount(): void
    {
        // store level 0 -> maxStorable = 10000
        $empty = $this->market([], ['planet_metal' => 0, 'building_metal_store' => 0]);
        $this->assertFalse($empty->isMetalStorageFull());

        $full = $this->market([], ['planet_metal' => 10000, 'building_metal_store' => 0]);
        $this->assertTrue($full->isMetalStorageFull());
    }

    public function testProjectedResourcesAddsFillToCurrentBelow100(): void
    {
        $market = $this->market([], ['planet_metal' => 0, 'building_metal_store' => 0]);

        // 50% of 10000 = 5000, added to the current 0
        $this->assertSame(5000.0, $market->getProjectedResouces('metal', 50));
    }

    public function testPriceToFill10PercentOfAnEmptyStore(): void
    {
        $market = $this->market([], ['planet_metal' => 0, 'building_metal_store' => 0]);

        // basePrice 4500, 10% of a full empty 10000 store -> 4500 dark matter
        $this->assertSame(4500.0, $market->getPriceToFill10Percent('metal'));
    }

    public function testRefillPayabilityDependsOnDarkMatter(): void
    {
        $planet = ['planet_metal' => 0, 'building_metal_store' => 0];

        $this->assertTrue($this->market(['premium_dark_matter' => 5000], $planet)->isRefillPayable('metal', 10));
        $this->assertFalse($this->market(['premium_dark_matter' => 100], $planet)->isRefillPayable('metal', 10));
    }

    public function testFullStorageIsNotFillable(): void
    {
        $market = $this->market([], ['planet_metal' => 10000, 'building_metal_store' => 0]);

        $this->assertFalse($market->isMetalStorageFillable(50));
    }
}
