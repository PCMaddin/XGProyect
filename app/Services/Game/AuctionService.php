<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\Enumerators\MessagesEnumerator;
use App\Libraries\Functions;
use App\Services\Game\Items\ItemCatalog;
use App\Services\Game\Items\ItemService;
use App\Services\Game\Items\ItemTexts;
use Illuminate\Support\Facades\DB;

/**
 * The auctioneer (trader), as in OGame (owiki.de, "Auktionator"): every full
 * hour from 6 to 23 o'clock (the last one starts at 23) an auction of one item starts (no field items).
 * It ends after 30 to 45 minutes, give or take 30 seconds; players only see
 * the time left in steps of 5 minutes. Bids are resources from the current
 * planet, counted in metal units (1 : 1,5 : 3); they are never refunded,
 * and a bid can be raised. The highest bid wins.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class AuctionService
{
    private const FIRST_HOUR = 6;
    private const MIN_DURATION = 30 * 60;
    private const MAX_DURATION = 45 * 60;
    private const END_SPREAD = 30;
    private const DISPLAY_STEP = 5 * 60;

    public function __construct(
        private ItemCatalog $catalog,
        private ItemService $items,
        private ItemTexts $texts,
    ) {
    }

    /**
     * The auction of the current hour (started on first sight), or null
     * outside the auction hours.
     *
     * @return array{id: int, starts_at: int, ends_at: int, item: string, winner_id: int|null, settled: bool}|null
     */
    public function current(int $now): ?array
    {
        $start = self::hourStart($now);
        if ((int) date('G', $start) < self::FIRST_HOUR) {
            return null;
        }

        $items = array_keys($this->catalog->tradable());
        DB::table('auctions')->insertOrIgnore([
            'starts_at' => $start,
            'ends_at' => $start + random_int(self::MIN_DURATION, self::MAX_DURATION) + random_int(-self::END_SPREAD, self::END_SPREAD),
            'item' => $items[array_rand($items)],
        ]);

        return $this->auction($start);
    }

    /**
     * When the next auction starts.
     */
    public function nextStart(int $now): int
    {
        $next = self::hourStart($now) + 3600;

        while ((int) date('G', $next) < self::FIRST_HOUR) {
            $next += 3600;
        }

        return $next;
    }

    /**
     * The time left as players see it: whole steps of 5 minutes, rounded up.
     */
    public function shownTimeLeft(int $endsAt, int $now): int
    {
        return (int) ceil(max(0, $endsAt - $now) / self::DISPLAY_STEP) * self::DISPLAY_STEP;
    }

    /**
     * The highest bid of an auction.
     *
     * @return array{user_id: int, value: float}|null
     */
    public function highestBid(int $auctionId): ?array
    {
        $bid = DB::table('auction_bids')->where('auction_id', $auctionId)->orderByDesc('value')->orderBy('updated_at')->first();

        return $bid === null ? null : ['user_id' => (int) $bid->user_id, 'value' => (float) $bid->value];
    }

    public function bidOf(int $auctionId, int $userId): float
    {
        $value = DB::table('auction_bids')->where('auction_id', $auctionId)->where('user_id', $userId)->value('value');

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Bid (or raise one's bid by) $amount of a resource of the planet. The
     * total must top the highest bid of the others. Error key, or null.
     */
    public function bid(int $userId, int $planetId, string $resource, int $amount, int $now): ?string
    {
        $rate = ImportExportService::RATES[$resource] ?? null;

        if ($rate === null || $amount <= 0) {
            return 'tr_au_invalid_bid';
        }

        return DB::transaction(function () use ($userId, $planetId, $resource, $amount, $rate, $now): ?string {
            $auction = DB::table('auctions')->where('starts_at', self::hourStart($now))->lockForUpdate()->first();

            if ($auction === null || (int) $auction->ends_at <= $now) {
                return 'tr_au_no_auction';
            }

            $auctionId = (int) $auction->id;
            $total = $this->bidOf($auctionId, $userId) + $amount * $rate;
            $highest = $this->highestBid($auctionId);

            if ($highest !== null && $highest['user_id'] !== $userId && $total <= $highest['value']) {
                return 'tr_au_too_low';
            }

            $paid = DB::table('planets')
                ->where('planet_id', $planetId)
                ->where('planet_user_id', $userId)
                ->where('planet_' . $resource, '>=', $amount)
                ->decrement('planet_' . $resource, $amount);

            if ($paid !== 1) {
                return 'tr_ie_not_enough';
            }

            DB::table('auction_bids')->updateOrInsert(
                ['auction_id' => $auctionId, 'user_id' => $userId],
                ['value' => $total, 'updated_at' => $now]
            );

            return null;
        });
    }

    /**
     * Hand the items of ended auctions to their winners.
     */
    public function settleEnded(int $now): void
    {
        foreach (DB::table('auctions')->where('settled', false)->where('ends_at', '<=', $now)->pluck('id') as $rawId) {
            $id = is_numeric($rawId) ? (int) $rawId : 0;

            DB::transaction(function () use ($id): void {
                $auction = DB::table('auctions')->where('id', $id)->where('settled', false)->lockForUpdate()->first();

                if ($auction === null) {
                    return;
                }

                $winner = $this->highestBid($id);
                DB::table('auctions')->where('id', $id)->update(['settled' => true, 'winner_id' => $winner['user_id'] ?? null]);

                if ($winner !== null) {
                    $this->items->add($winner['user_id'], (string) $auction->item);
                    $this->tellWinner($winner['user_id'], (string) $auction->item, (int) $auction->ends_at);
                }
            });
        }
    }

    /**
     * @return array{id: int, starts_at: int, ends_at: int, item: string, winner_id: int|null, settled: bool}|null
     */
    private function auction(int $start): ?array
    {
        $row = DB::table('auctions')->where('starts_at', $start)->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'starts_at' => (int) $row->starts_at,
            'ends_at' => (int) $row->ends_at,
            'item' => (string) $row->item,
            'winner_id' => $row->winner_id === null ? null : (int) $row->winner_id,
            'settled' => (bool) $row->settled,
        ];
    }

    private function tellWinner(int $userId, string $key, int $time): void
    {
        Functions::sendMessage(
            $userId,
            0,
            $time,
            MessagesEnumerator::GENERAL,
            (string) __('game/trader.tr_auctioneer'),
            (string) __('game/trader.tr_au_won_subject'),
            sprintf((string) __('game/trader.tr_au_won'), $this->texts->name($key))
        );
    }

    private static function hourStart(int $time): int
    {
        return (int) strtotime(date('Y-m-d H:00:00', $time));
    }
}
