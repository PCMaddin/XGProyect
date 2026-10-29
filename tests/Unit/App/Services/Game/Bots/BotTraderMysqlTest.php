<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Bots;

use App\Models\User;
use App\Services\Admin\BotService;
use App\Services\Game\AuctionService;
use App\Services\Game\Bots\BotBidder;
use App\Services\Game\Bots\BotPlayerService;
use App\Services\Game\Bots\BotTrader;
use App\Services\Game\Items\ItemService;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Testing\Fakes\ExceptionHandlerFake;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * Bots at the trader on a real database: a bot stuck for crystal sells its
 * metal to the merchant (an expedition's offer for free, else a call for
 * dark matter if half its surplus pays the shortfall, at most once in 6
 * hours); bots with a large surplus bid for
 * the auction's item and raise when outbid, buy the import/export container
 * and use what they get. Without a surplus they do none of it.
 */
#[CoversClass(BotTrader::class)]
#[CoversClass(BotBidder::class)]
class BotTraderMysqlTest extends MysqlTestCase
{
    private int $bot;

    private int $planet;

    private int $other;

    private int $otherPlanet;

    protected function setUp(): void
    {
        parent::setUp();

        config(['version.files' => '4.0.0']);
        // a bot turn reports its errors instead of failing: none may occur
        Exceptions::fake();
        $this->seed(OptionsTableSeeder::class);

        $this->assertSame(2, app(BotService::class)->createBots(2, 1, 1));

        [$this->bot, $this->other] = User::where('user_is_bot', true)->orderBy('id')->pluck('id')->map(self::int(...))->all();
        $this->planet = User::findOrFail($this->bot)->home_planet_id;
        $this->otherPlanet = User::findOrFail($this->other)->home_planet_id;
    }

    protected function tearDown(): void
    {
        // skipped without MariaDB: nothing was faked
        if (Exceptions::getFacadeRoot() instanceof ExceptionHandlerFake) {
            Exceptions::assertNothingReported();
        }

        parent::tearDown();
    }

    public function testATurnUsesTheItemsTheBotOwns(): void
    {
        app(ItemService::class)->add($this->bot, 'metal_booster_bronze');
        // field items are no bot business
        app(ItemService::class)->add($this->bot, 'planet_fields_bronze');

        app(BotPlayerService::class)->play($this->bot, time());

        $booster = DB::table('planet_boosters')->where('planet_id', $this->planet)->where('resource', 'metal')->first();
        $this->assertNotNull($booster);
        $this->assertSame(10, self::int($booster->percent));
        $this->assertSame(['planet_fields_bronze' => 1], app(ItemService::class)->inventory($this->bot));
    }

    public function testAStuckBotCallsTheMerchantAndSellsItsMetal(): void
    {
        $this->stuckForCrystal(10000);

        $this->trade();

        $this->assertSame(6500, $this->value('premium', 'premium_user_id', $this->bot, 'premium_dark_matter'));
        $this->assertNull(DB::table('premium')->where('premium_user_id', $this->bot)->value('premium_merchant_offer'));
        // twice the 181.339 crystal of crystal mine 20 and the 800 of energy technology 1
        $this->assertSame(364278, $this->value('planets', 'planet_id', $this->planet, 'planet_crystal'));
        // 2 crystal for 3 metal times a factor of 0,7 to 1
        $paid = 2000000 - $this->value('planets', 'planet_id', $this->planet, 'planet_metal');
        $this->assertGreaterThanOrEqual(364278 * 1.5, $paid);
        $this->assertLessThanOrEqual(ceil(364278 * 1.5 / 0.7), $paid);

        // stuck again right away: no second call within 6 hours
        $this->stuckForCrystal(10000);
        $this->trade();
        $this->assertSame(10000, $this->value('premium', 'premium_user_id', $this->bot, 'premium_dark_matter'));
        $this->assertSame(0, $this->value('planets', 'planet_id', $this->planet, 'planet_crystal'));
    }

    public function testAnExpeditionOfferIsUsedWithoutDarkMatter(): void
    {
        $this->stuckForCrystal(0);
        DB::table('premium')->where('premium_user_id', $this->bot)->update(['premium_merchant_offer' => json_encode(['sell' => 'metal', 'factor' => 0.9])]);

        $this->trade();

        $this->assertNull(DB::table('premium')->where('premium_user_id', $this->bot)->value('premium_merchant_offer'));
        // twice the shortfall, at 0,6 crystal per metal
        $this->assertSame(364278, $this->value('planets', 'planet_id', $this->planet, 'planet_crystal'));
        $this->assertSame(2000000 - (int) ceil(364278 / 0.6), $this->value('planets', 'planet_id', $this->planet, 'planet_metal'));
    }

    public function testNoTradeWithoutASurplus(): void
    {
        // the crystal mine itself costs 362.678 metal: half of the 137.322 left
        // over does not pay the 182.139 crystal missing, not even at the best rate
        $this->stuckForCrystal(10000);
        DB::table('planets')->where('planet_id', $this->planet)->update(['planet_metal' => 500000, 'planet_deuterium' => 50000]);
        // he buys crystal, which the bot lacks itself
        DB::table('premium')->where('premium_user_id', $this->bot)->update(['premium_merchant_offer' => json_encode(['sell' => 'crystal', 'factor' => 1.0])]);

        $this->trade();

        $this->assertSame(10000, $this->value('premium', 'premium_user_id', $this->bot, 'premium_dark_matter'));
        $this->assertSame(0, $this->value('planets', 'planet_id', $this->planet, 'planet_crystal'));
        $this->assertSame(500000, $this->value('planets', 'planet_id', $this->planet, 'planet_metal'));
        $this->assertSame(50000, $this->value('planets', 'planet_id', $this->planet, 'planet_deuterium'));
        $this->assertNotNull(DB::table('premium')->where('premium_user_id', $this->bot)->value('premium_merchant_offer'));
    }

    public function testBotsBidAndRaiseAtTheAuctionAndUseTheItemTheyWin(): void
    {
        $start = (int) strtotime('today 14:00');
        DB::table('auctions')->insert(['starts_at' => $start, 'ends_at' => $start + 40 * 60, 'item' => 'metal_booster_gold']);
        $this->rich($this->planet);
        $this->rich($this->otherPlanet);

        $this->boughtTheContainer($this->bot, $start);
        $this->boughtTheContainer($this->other, $start);

        $auction = self::int(DB::table('auctions')->value('id'));

        // worth 30 % of 10.000 metal for a week, limited to a quarter of the surplus: opens at a quarter of that
        $this->act($this->bot, $this->planet, $start + 60);
        $first = app(AuctionService::class)->bidOf($auction, $this->bot);
        $this->assertGreaterThan(100000, $first);
        $this->assertLessThanOrEqual(126000, $first);

        // the other tops it by 10 %, the first raises
        $this->act($this->other, $this->otherPlanet, $start + 600);
        $this->assertEqualsWithDelta($first * 1.1, app(AuctionService::class)->bidOf($auction, $this->other), 1);
        $this->act($this->bot, $this->planet, $start + 1200);
        $this->assertSame($this->bot, app(AuctionService::class)->highestBid($auction)['user_id'] ?? 0);
        $this->assertEqualsWithDelta($first * 1.21, app(AuctionService::class)->bidOf($auction, $this->bot), 2);

        // without a surplus, the other gives up
        DB::table('planets')->where('planet_id', $this->otherPlanet)->update(['planet_metal' => 150000]);
        $this->act($this->other, $this->otherPlanet, $start + 1800);
        $this->assertEqualsWithDelta($first * 1.1, app(AuctionService::class)->bidOf($auction, $this->other), 1);

        // the winner uses the booster on its next turn
        app(AuctionService::class)->settleEnded($start + 40 * 60);
        $this->act($this->bot, $this->planet, $start + 3000);
        $this->assertSame(30, self::int(DB::table('planet_boosters')->where('planet_id', $this->planet)->value('percent')));
        $this->assertSame([], app(ItemService::class)->inventory($this->bot));
    }

    public function testABotWithASurplusBuysTheContainerOfTheDayOnce(): void
    {
        $now = self::night();
        $this->rich($this->planet);
        // the poor one does not buy
        DB::table('planets')->where('planet_id', $this->otherPlanet)->update(['planet_metal' => 150000]);

        $this->act($this->bot, $this->planet, $now);
        $this->act($this->other, $this->otherPlanet, $now);

        $this->assertSame(1, self::int(DB::table('import_exports')->where('user_id', $this->bot)->value('bought')));
        $this->assertSame(0, self::int(DB::table('import_exports')->where('user_id', $this->other)->value('bought')));
        $metal = $this->value('planets', 'planet_id', $this->planet, 'planet_metal');
        $this->assertLessThanOrEqual(2000000 - 40000, $metal);

        $this->act($this->bot, $this->planet, $now + 600);
        // bought once; the item it got may have been used
        $this->assertLessThanOrEqual(1, array_sum(app(ItemService::class)->inventory($this->bot)));
        $this->assertSame($metal, $this->value('planets', 'planet_id', $this->planet, 'planet_metal'));
        $this->assertSame(0, DB::table('auction_bids')->count());
    }

    /**
     * A developed home planet that wants crystal mine 20 and energy
     * technology 1 but has no crystal, 2.000.000 metal, and the dark matter.
     */
    private function stuckForCrystal(int $darkMatter): void
    {
        DB::table('buildings')->where('building_planet_id', $this->planet)->update([
            'building_metal_mine' => 22, 'building_crystal_mine' => 19, 'building_deuterium_sintetizer' => 18,
            'building_solar_plant' => 22, 'building_robot_factory' => 7, 'building_hangar' => 7, 'building_laboratory' => 10,
            'building_metal_store' => 9, 'building_crystal_store' => 6, 'building_deuterium_tank' => 4,
        ]);
        DB::table('planets')->where('planet_id', $this->planet)->update([
            'planet_metal' => 2000000, 'planet_crystal' => 0, 'planet_deuterium' => 100000,
            'planet_metal_perhour' => 10000, 'planet_crystal_perhour' => 1000, 'planet_deuterium_perhour' => 2000,
            'planet_energy_max' => 5000, 'planet_energy_used' => -1000, 'planet_last_update' => time(),
        ]);
        DB::table('premium')->where('premium_user_id', $this->bot)->update(['premium_dark_matter' => $darkMatter]);
        $this->boughtTheContainer($this->bot, self::night());
    }

    /**
     * The bot bought the import/export container of that day already.
     */
    private function boughtTheContainer(int $bot, int $time): void
    {
        DB::table('import_exports')->insertOrIgnore(['user_id' => $bot, 'day' => date('Y-m-d', $time), 'item' => 'kraken_bronze', 'bought' => true]);
    }

    /**
     * A time without an auction.
     */
    private static function night(): int
    {
        return (int) strtotime('today 03:30');
    }

    /**
     * 2.000.000 metal, producing 10.000 metal, 5.000 crystal and 2.000 deuterium an hour.
     */
    private function rich(int $planetId): void
    {
        DB::table('planets')->where('planet_id', $planetId)->update([
            'planet_metal' => 2000000, 'planet_crystal' => 0, 'planet_deuterium' => 0,
            'planet_metal_perhour' => 10000, 'planet_crystal_perhour' => 5000, 'planet_deuterium_perhour' => 2000,
        ]);
    }

    private function trade(): void
    {
        $this->act($this->bot, $this->planet, self::night());
    }

    private function act(int $bot, int $planet, int $now): void
    {
        app(BotTrader::class)->act(['id' => $bot], $planet, $now);
    }

    private function value(string $table, string $key, int $id, string $column): int
    {
        return self::int(DB::table($table)->where($key, $id)->value($column));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
