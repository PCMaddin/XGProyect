<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Services\Game\AuctionService;
use App\Services\Game\Items\ItemCatalog;
use App\Services\Game\Items\ItemService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The auctioneer as in the original: an auction every full hour from 6 to
 * 23 o'clock, ending after 30 to 45 minutes; bids in metal units, never
 * refunded, raised by the bidder; the highest bid wins the item.
 */
#[CoversClass(AuctionService::class)]
class AuctionServiceTest extends DatabaseTestCase
{
    private int $hour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hour = (int) strtotime('2026-10-16 14:00:00');

        foreach ([1, 2] as $id) {
            DB::table('users')->insert(['id' => $id, 'name' => 'Player' . $id, 'email' => 'p' . $id . '@example.com', 'password' => 'x', 'home_planet_id' => $id, 'current_planet' => $id]);
            DB::table('planets')->insert(['planet_id' => $id, 'planet_user_id' => $id, 'planet_metal' => 100000, 'planet_crystal' => 100000, 'planet_deuterium' => 100000]);
        }
    }

    public function testAnAuctionEveryHourFromSixToTwentyThree(): void
    {
        $auction = $this->auctions()->current($this->hour + 60);
        $this->assertNotNull($auction);
        $this->assertSame($this->hour, $auction['starts_at']);
        $this->assertGreaterThanOrEqual($this->hour + 30 * 60 - 30, $auction['ends_at']);
        $this->assertLessThanOrEqual($this->hour + 45 * 60 + 30, $auction['ends_at']);
        $this->assertArrayHasKey($auction['item'], app(ItemCatalog::class)->tradable());
        // the same auction for everybody that hour
        $this->assertSame($auction, $this->auctions()->current($this->hour + 600));

        $this->assertNull($this->auctions()->current((int) strtotime('2026-10-16 03:20:00')));
        $this->assertSame((int) strtotime('2026-10-16 06:00:00'), $this->auctions()->nextStart((int) strtotime('2026-10-16 03:20:00')));
        $this->assertSame((int) strtotime('2026-10-17 06:00:00'), $this->auctions()->nextStart((int) strtotime('2026-10-16 23:50:00')));
        $this->assertSame($this->hour + 3600, $this->auctions()->nextStart($this->hour + 60));
    }

    public function testTheTimeLeftIsShownInStepsOfFiveMinutes(): void
    {
        $this->assertSame(35 * 60, $this->auctions()->shownTimeLeft(1000 + 31 * 60, 1000));
        $this->assertSame(5 * 60, $this->auctions()->shownTimeLeft(1060, 1000));
        $this->assertSame(0, $this->auctions()->shownTimeLeft(1000, 2000));
    }

    public function testBidsMustTopTheOthersAndAreNeverRefunded(): void
    {
        $this->start();

        $this->assertNull($this->auctions()->bid(1, 1, 'crystal', 1000, $this->hour + 60));
        // 1.500 metal units to beat: 1.500 metal are not enough, 501 deuterium are
        $this->assertSame('tr_au_too_low', $this->auctions()->bid(2, 2, 'metal', 1500, $this->hour + 60));
        $this->assertNull($this->auctions()->bid(2, 2, 'deuterium', 501, $this->hour + 60));
        // the first raises his bid
        $this->assertNull($this->auctions()->bid(1, 1, 'metal', 10, $this->hour + 120));
        $this->assertSame('tr_ie_not_enough', $this->auctions()->bid(2, 2, 'metal', 200000, $this->hour + 120));
        $this->assertSame('tr_au_invalid_bid', $this->auctions()->bid(2, 2, 'energy', 5, $this->hour + 120));

        $auctionId = $this->auctions()->current($this->hour + 120)['id'] ?? 0;
        $this->assertSame(['user_id' => 1, 'value' => 1510.0], $this->auctions()->highestBid($auctionId));
        $this->assertSame(1503.0, $this->auctions()->bidOf($auctionId, 2));
        $this->assertSame(99000, $this->resource(1, 'crystal'));
        $this->assertSame(99499, $this->resource(2, 'deuterium'));
    }

    public function testTheHighestBidWinsTheItemWhenTheAuctionEnds(): void
    {
        $auction = $this->start();
        $this->auctions()->bid(1, 1, 'metal', 5000, $this->hour + 60);
        $this->auctions()->bid(2, 2, 'metal', 6000, $this->hour + 60);

        $this->assertSame('tr_au_no_auction', $this->auctions()->bid(1, 1, 'metal', 5000, $auction['ends_at']));

        $this->auctions()->settleEnded($auction['ends_at'] - 1);
        $this->assertSame([], app(ItemService::class)->inventory(2));

        $this->auctions()->settleEnded($auction['ends_at']);
        $this->auctions()->settleEnded($auction['ends_at'] + 60);
        $this->assertSame([$auction['item'] => 1], app(ItemService::class)->inventory(2));
        $this->assertSame([], app(ItemService::class)->inventory(1));
        $this->assertSame(1, DB::table('auctions')->where('winner_id', 2)->count());
        $this->assertSame(1, DB::table('messages')->where('message_receiver', 2)->count());
        // the loser's resources are gone
        $this->assertSame(95000, $this->resource(1, 'metal'));
    }

    /**
     * @return array{id: int, starts_at: int, ends_at: int, item: string, winner_id: int|null, settled: bool}
     */
    private function start(): array
    {
        $auction = $this->auctions()->current($this->hour + 30);
        $this->assertNotNull($auction);

        return $auction;
    }

    private function resource(int $planetId, string $resource): int
    {
        $value = DB::table('planets')->where('planet_id', $planetId)->value('planet_' . $resource);
        $this->assertIsNumeric($value);

        return (int) $value;
    }

    private function auctions(): AuctionService
    {
        return app(AuctionService::class);
    }
}
