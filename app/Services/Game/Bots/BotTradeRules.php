<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Services\Game\ImportExportService;
use App\Services\Game\Items\Item;

/**
 * How a bot trades, as plain arithmetic: what it has spare and what it
 * lacks for its next building and research, what it buys from the merchant,
 * what an item is worth to it and how high it bids. Amounts of different
 * resources are compared in metal units (1 : 1,5 : 3), as the auctioneer
 * and import/export count them.
 *
 * Only decides; {@see BotTrader} reads the state and acts through the
 * players' services.
 */
class BotTradeRules
{
    public const RESOURCES = ['metal', 'crystal', 'deuterium'];

    /** a surplus worth a paid merchant, a bid or a container: a day of the planet's production */
    private const LARGE_SURPLUS_HOURS = 24;

    /** and at least this many metal units */
    private const MIN_LARGE_SURPLUS = 50000;

    /** the merchant pays off when the mines need longer than this to cover a shortfall */
    public const WAIT_HOURS = 6;

    /** the merchant gets at most this share of the surplus */
    private const SELL_SHARE = 0.5;

    /** buy up to this many times the shortfall, the next step's share included */
    private const BUY_FACTOR = 2;

    /** bids and containers get at most this share of the surplus */
    private const SPEND_SHARE = 0.25;

    /** the first bid: this share of the limit */
    private const OPENING_SHARE = 0.25;

    /** a raise tops the highest bid by this share, at least MIN_RAISE metal units */
    private const RAISE_SHARE = 0.1;

    private const MIN_RAISE = 1000;

    /**
     * The stock above what the next steps cost (surplus), and what they
     * cost beyond the stock (shortfall).
     *
     * @param array<string, float> $stock
     * @param array<string, float> $need
     *
     * @return array{surplus: array<string, float>, shortfall: array<string, float>}
     */
    public function balance(array $stock, array $need): array
    {
        $balance = ['surplus' => [], 'shortfall' => []];

        foreach (self::RESOURCES as $resource) {
            $left = ($stock[$resource] ?? 0.0) - ($need[$resource] ?? 0.0);
            $balance['surplus'][$resource] = max(0.0, $left);
            $balance['shortfall'][$resource] = max(0.0, -$left);
        }

        return $balance;
    }

    /**
     * The resource of the largest surplus, if that surplus is large: worth
     * a day of the planet's production, at least 50.000 metal units.
     *
     * @param array<string, float> $surplus
     * @param array<string, float> $perHour production of the planet
     */
    public function surplusResource(array $surplus, array $perHour): ?string
    {
        $sell = $this->sellResource($surplus);
        $large = max(self::MIN_LARGE_SURPLUS, self::LARGE_SURPLUS_HOURS * $this->hourlyValue($perHour));

        return $sell !== null && $this->value($sell, $surplus[$sell] ?? 0.0) >= $large ? $sell : null;
    }

    /**
     * The resource of the largest surplus (in metal units), if any.
     *
     * @param array<string, float> $surplus
     */
    public function sellResource(array $surplus): ?string
    {
        $best = null;
        $bestValue = 0.0;

        foreach (self::RESOURCES as $resource) {
            $value = $this->value($resource, $surplus[$resource] ?? 0.0);

            if ($value > $bestValue) {
                $best = $resource;
                $bestValue = $value;
            }
        }

        return $best;
    }

    /**
     * Whether the purchase pays the whole shortfall of what it buys.
     *
     * @param array<string, int>   $purchase
     * @param array<string, float> $shortfall
     */
    public function covers(array $purchase, array $shortfall, string $sell): bool
    {
        foreach ($shortfall as $resource => $missing) {
            if ($resource !== $sell && $missing > ($purchase[$resource] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * An amount of a resource in metal units.
     */
    private function value(string $resource, float $amount): float
    {
        return $amount * (ImportExportService::RATES[$resource] ?? 0.0);
    }

    /**
     * Whether a shortfall is worth the merchant: the planet's own production
     * would take longer than WAIT_HOURS to cover it.
     *
     * @param array<string, float> $shortfall
     * @param array<string, float> $perHour production of the planet
     */
    public function worthTrading(array $shortfall, array $perHour): bool
    {
        foreach ($shortfall as $resource => $missing) {
            if ($missing > 0 && $missing > max(0.0, $perHour[$resource] ?? 0.0) * self::WAIT_HOURS) {
                return true;
            }
        }

        return false;
    }

    /**
     * What to buy from the merchant who buys $sell: up to twice each
     * shortfall, as much as fits into the storage, for at most half the
     * surplus of $sell.
     *
     * @param array<string, float> $shortfall
     * @param array<string, float> $freeStorage room left in the storages
     * @param array<string, float> $rates       units of each resource the merchant gives per unit of $sell
     *
     * @return array<string, int> resource => amount
     */
    public function purchase(string $sell, float $surplus, array $shortfall, array $freeStorage, array $rates): array
    {
        $wanted = [];
        $cost = 0.0;

        foreach ($shortfall as $resource => $missing) {
            $rate = $rates[$resource] ?? 0.0;

            if ($resource === $sell || $missing <= 0 || $rate <= 0) {
                continue;
            }

            // one unit below the limit: the stock is not a whole number
            $wanted[$resource] = max(0.0, min(self::BUY_FACTOR * $missing, floor($freeStorage[$resource] ?? 0.0) - 1));
            $cost += $wanted[$resource] / $rate;
        }

        $budget = $surplus * self::SELL_SHARE;
        $scale = $cost > $budget ? $budget / $cost : 1.0;

        return array_filter(
            array_map(static fn (float $amount): int => (int) floor($amount * $scale), $wanted),
            static fn (int $amount): bool => $amount > 0
        );
    }

    /**
     * The most a bot spends on an item (metal units): a quarter of its
     * surplus of the resource it pays with (what it bid already counted
     * in), never more than the item is worth to it.
     */
    public function spendLimit(string $sell, float $surplus, float $worth, float $bidAlready = 0.0): float
    {
        return min($worth, ($this->value($sell, $surplus) + $bidAlready) * self::SPEND_SHARE);
    }

    /**
     * What an item is worth to the bot (metal units), used on the planet:
     * a booster the extra production of its week, kraken, detroid and
     * newtron what the planet produces in the time they save.
     *
     * @param array<string, float> $perHour production of the planet
     */
    private function itemWorth(Item $item, array $perHour): float
    {
        if ($item->type === Item::BOOSTER) {
            $resource = (string) $item->resource;

            return $this->value($resource, max(0.0, $perHour[$resource] ?? 0.0) * $item->value / 100 * $item->duration / 3600);
        }

        if (!in_array($item->type, [Item::KRAKEN, Item::DETROID, Item::NEWTRON], true)) {
            return 0.0;
        }

        return $this->hourlyValue($perHour) * $item->value / 3600;
    }

    /**
     * What the planet produces in an hour (metal units).
     *
     * @param array<string, float> $perHour
     */
    private function hourlyValue(array $perHour): float
    {
        $hourly = 0.0;

        foreach (self::RESOURCES as $resource) {
            $hourly += $this->value($resource, max(0.0, $perHour[$resource] ?? 0.0));
        }

        return $hourly;
    }

    /**
     * What an item of these (one of them unknown) is worth on average.
     *
     * @param array<array-key, Item> $items
     * @param array<string, float>   $perHour
     */
    public function worth(array $items, array $perHour): float
    {
        if ($items === []) {
            return 0.0;
        }

        $sum = 0.0;

        foreach ($items as $item) {
            $sum += $this->itemWorth($item, $perHour);
        }

        return $sum / count($items);
    }

    /**
     * The total the bot bids now (metal units), or null: it opens at a
     * quarter of its limit and tops the highest bid of another by 10 %,
     * up to its limit; leading, it waits.
     *
     * @param array{user_id: int, value: float}|null $highest
     */
    public function bidTarget(?array $highest, float $own, float $limit, int $userId): ?float
    {
        if ($highest !== null && $highest['user_id'] === $userId) {
            return null;
        }

        $top = $highest['value'] ?? 0.0;
        $target = $highest === null
            ? $limit * self::OPENING_SHARE
            : max($top * (1 + self::RAISE_SHARE), $top + self::MIN_RAISE);
        $target = floor(min($target, $limit));

        return $target > $top && $target > $own ? $target : null;
    }
}
