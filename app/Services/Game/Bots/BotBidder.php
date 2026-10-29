<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Services\Game\AuctionService;
use App\Services\Game\ImportExportService;
use App\Services\Game\Items\ItemCatalog;

/**
 * A bot with a large surplus at the auctioneer and at import/export, paying
 * with the resource of its surplus from the home planet, as the trader
 * pages do for a player:
 *
 * - it bids for the item of the running auction and raises when outbid;
 * - it buys the container of the day, if an unknown item of its tier is
 *   worth the price on average.
 *
 * Both up to a quarter of the surplus and never above what the item is
 * worth to it ({@see BotTradeRules}). It never swaps a container: another
 * unknown item of the same tier is worth the same.
 */
class BotBidder
{
    public function __construct(
        private BotTradeRules $rules,
        private AuctionService $auctions,
        private ImportExportService $importExport,
        private ItemCatalog $catalog,
    ) {
    }

    /**
     * @param array<string, float> $surplus the home planet's stock above its needs
     * @param array<string, float> $perHour the home planet's production
     */
    public function bid(int $userId, int $planetId, array $surplus, array $perHour, int $now): void
    {
        $sell = $this->rules->surplusResource($surplus, $perHour);
        $auction = $sell === null ? null : $this->auctions->current($now);
        $item = $auction === null ? null : $this->catalog->get($auction['item']);

        if ($sell === null || $auction === null || $item === null || $auction['ends_at'] <= $now) {
            return;
        }

        $own = $this->auctions->bidOf($auction['id'], $userId);
        $limit = $this->rules->spendLimit($sell, $surplus[$sell], $this->rules->worth([$item], $perHour), $own);
        $target = $this->rules->bidTarget($this->auctions->highestBid($auction['id']), $own, $limit, $userId);

        if ($target !== null) {
            $amount = (int) ceil(($target - $own) / ImportExportService::RATES[$sell]);
            $this->auctions->bid($userId, $planetId, $sell, $amount, $now);
        }
    }

    /**
     * @param array<string, float> $surplus the home planet's stock above its needs
     * @param array<string, float> $perHour the home planet's production
     */
    public function buyContainer(int $userId, int $planetId, array $surplus, array $perHour, int $now): void
    {
        $sell = $this->rules->surplusResource($surplus, $perHour);

        if ($sell === null) {
            return;
        }

        $day = date('Y-m-d', $now);
        $container = $this->importExport->container($day);
        $worth = $this->rules->worth($this->catalog->tradable($container['tier']), $perHour);

        if ($container['price'] <= $this->rules->spendLimit($sell, $surplus[$sell], $worth) &&
            !$this->importExport->state($userId, $day)['bought']) {
            $this->importExport->buy($userId, $planetId, $sell, $day);
        }
    }
}
