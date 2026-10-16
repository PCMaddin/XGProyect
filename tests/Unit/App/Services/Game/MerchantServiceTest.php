<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Models\Options;
use App\Services\Game\Formulas\ProductionService;
use App\Services\Game\MerchantService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The merchant: one call costs dark matter and fixes the rates (base rates
 * 3/2/1 times a factor drawn between the minimum and maximum), one exchange
 * uses the offer up; resources sold must be there, resources bought must fit
 * into the storage.
 */
#[CoversClass(MerchantService::class)]
class MerchantServiceTest extends DatabaseTestCase
{
    private const USER = 7;

    private const PLANET = 70;

    protected function setUp(): void
    {
        parent::setUp();

        $options = [
            'merchant_price' => '3500', 'merchant_base_min_exchange_rate' => '0.7', 'merchant_base_max_exchange_rate' => '1',
            'merchant_metal_multiplier' => '3', 'merchant_crystal_multiplier' => '2', 'merchant_deuterium_multiplier' => '1',
        ];

        foreach ($options as $name => $value) {
            Options::insert(['name' => $name, 'value' => $value, 'type' => 'float']);
        }

        DB::table('premium')->insert(['premium_user_id' => self::USER, 'premium_dark_matter' => 5000]);
        DB::table('planets')->insert(['planet_id' => self::PLANET, 'planet_user_id' => self::USER, 'planet_metal' => 100000, 'planet_crystal' => 1000, 'planet_deuterium' => 0]);
        // storage level 0 holds 10000 of each resource
        DB::table('buildings')->insert(['building_planet_id' => self::PLANET]);
    }

    public function testTheRatesFollowTheBaseRatesAndTheFactor(): void
    {
        $offer = ['sell' => 'metal', 'factor' => 0.88];

        $this->assertEqualsWithDelta(2 * 0.88 / 3, $this->merchant()->rate($offer, 'crystal'), 1e-9);
        $this->assertEqualsWithDelta(0.88 / 3, $this->merchant()->rate($offer, 'deuterium'), 1e-9);
        // 1000 crystal and 100 deuterium for metal, rounded up
        $this->assertSame((int) ceil(1000 / (2 * 0.88 / 3) + 100 / (0.88 / 3)), $this->merchant()->cost($offer, ['crystal' => 1000, 'deuterium' => 100]));
    }

    public function testCallingCostsDarkMatterAndDrawsTheFactor(): void
    {
        $this->assertNull($this->merchant()->call(self::USER, 'metal'));

        $this->assertSame(1500, $this->darkMatter());
        $offer = $this->merchant()->offer(self::USER);
        $this->assertNotNull($offer);
        $this->assertSame('metal', $offer['sell']);
        $this->assertGreaterThanOrEqual(0.7, $offer['factor']);
        $this->assertLessThanOrEqual(1.0, $offer['factor']);
    }

    public function testNoCallWithoutEnoughDarkMatterOrAResource(): void
    {
        $this->assertNull($this->merchant()->call(self::USER, 'metal'));
        $this->assertSame('tr_no_enough_dark_matter', $this->merchant()->call(self::USER, 'crystal'));
        $this->assertSame('metal', $this->merchant()->offer(self::USER)['sell'] ?? null);

        $this->assertSame('tr_select_resource', $this->merchant()->call(self::USER, 'darkmatter'));
        $this->assertSame(1500, $this->darkMatter());
    }

    public function testAnExchangeTradesAndUsesTheOfferUp(): void
    {
        $this->offer('metal', 0.9);

        $this->assertNull($this->merchant()->exchange(self::USER, self::PLANET, ['crystal' => 600, 'deuterium' => 300, 'metal' => 999]));

        $cost = (int) ceil(600 / (2 * 0.9 / 3) + 300 / (0.9 / 3));
        $this->assertSame([100000 - $cost, 1600, 300], $this->resources());
        $this->assertNull($this->merchant()->offer(self::USER));
        $this->assertSame(['tr_no_merchant', ''], $this->merchant()->exchange(self::USER, self::PLANET, ['crystal' => 1]));
    }

    public function testNothingChangesWhenTheExchangeIsNotPossible(): void
    {
        $this->offer('crystal', 1.0);

        // 1000 crystal buy at most 1500 metal
        $this->assertSame(['tr_not_enough_resources', 'crystal'], $this->merchant()->exchange(self::USER, self::PLANET, ['metal' => 1501]));
        $this->assertSame(['tr_nothing_to_exchange', ''], $this->merchant()->exchange(self::USER, self::PLANET, ['metal' => 0, 'crystal' => 50]));
        $this->assertSame(['tr_no_merchant', ''], $this->merchant()->exchange(self::USER, self::PLANET + 1, ['deuterium' => 10]));

        $this->offer('metal', 1.0);
        // the storage holds 10000 crystal, 1000 are there
        $this->assertSame(['tr_no_enough_storage_for', 'crystal'], $this->merchant()->exchange(self::USER, self::PLANET, ['crystal' => 9001]));

        $this->assertSame([100000, 1000, 0], $this->resources());
        $this->assertNotNull($this->merchant()->offer(self::USER));
        $this->assertSame(10000, app(ProductionService::class)->maxStorable(0));
    }

    private function darkMatter(): int
    {
        $value = DB::table('premium')->where('premium_user_id', self::USER)->value('premium_dark_matter');

        return is_numeric($value) ? (int) $value : -1;
    }

    private function merchant(): MerchantService
    {
        return app(MerchantService::class);
    }

    private function offer(string $sell, float $factor): void
    {
        DB::table('premium')->where('premium_user_id', self::USER)->update(['premium_merchant_offer' => json_encode(['sell' => $sell, 'factor' => $factor])]);
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
