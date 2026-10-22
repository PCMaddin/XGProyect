<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Services\Game\Items\ItemCatalog;
use App\Services\Game\Items\ItemService;
use Illuminate\Support\Facades\DB;

/**
 * Import/export (trader), as in OGame (owiki.de, "Import/Export"): every
 * day one container with an unknown item. Its tier and price are the same
 * for all players that day; gold is rarer than silver, silver than bronze.
 * The price is in metal units (bronze 40.000-50.000, silver 60.000-100.000,
 * gold 100.000-200.000) and is paid in one resource at 1 : 1,5 : 3. Each
 * player can buy one container a day and swap it twice for another of the
 * same tier (500 / 1.500 / 4.500 dark matter).
 *
 * How often each tier comes is not known: 70 / 25 / 5 % here.
 */
class ImportExportService
{
    public const MAX_SWAPS = 2;

    /** @var array<string, int> */
    private const TIER_WEIGHTS = ['bronze' => 70, 'silver' => 25, 'gold' => 5];

    /** @var array<string, array{0: int, 1: int}> */
    private const PRICES = ['bronze' => [40000, 50000], 'silver' => [60000, 100000], 'gold' => [100000, 200000]];

    /** @var array<string, int> */
    private const SWAP_PRICES = ['bronze' => 500, 'silver' => 1500, 'gold' => 4500];

    /** @var array<string, float> metal units per unit of the resource */
    public const RATES = ['metal' => 1.0, 'crystal' => 1.5, 'deuterium' => 3.0];

    public function __construct(
        private ItemCatalog $catalog,
        private ItemService $items,
    ) {
    }

    /**
     * The container of the day: tier and price (in metal units).
     *
     * @return array{tier: string, price: int}
     */
    public function container(string $day): array
    {
        $roll = self::roll($day . 'tier', 1, array_sum(self::TIER_WEIGHTS));
        $tier = 'bronze';

        foreach (self::TIER_WEIGHTS as $candidate => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                $tier = $candidate;
                break;
            }
        }

        [$min, $max] = self::PRICES[$tier];

        return ['tier' => $tier, 'price' => self::roll($day . 'price', $min / 1000, $max / 1000) * 1000];
    }

    /**
     * What the container costs in the resource.
     */
    public function cost(int $price, string $resource): int
    {
        return (int) ceil($price / (self::RATES[$resource] ?? 1.0));
    }

    public function swapPrice(string $tier): int
    {
        return self::SWAP_PRICES[$tier] ?? 0;
    }

    /**
     * The player's state of the day: swaps used, whether bought, and (once
     * bought) the item.
     *
     * @return array{swaps: int, bought: bool, item: string}
     */
    public function state(int $userId, string $day): array
    {
        $row = $this->row($userId, $day);

        return ['swaps' => $row['swaps'], 'bought' => $row['bought'], 'item' => $row['item']];
    }

    /**
     * Swap the container for another of the same tier. Error key or null.
     */
    public function swap(int $userId, string $day): ?string
    {
        $tier = $this->container($day)['tier'];
        $price = $this->swapPrice($tier);

        return DB::transaction(function () use ($userId, $day, $tier, $price): ?string {
            $row = $this->row($userId, $day);

            if ($row['bought']) {
                return 'tr_ie_already_bought';
            }

            if ($row['swaps'] >= self::MAX_SWAPS) {
                return 'tr_ie_no_swaps';
            }

            $paid = DB::table('premium')->where('premium_user_id', $userId)->where('premium_dark_matter', '>=', $price)->decrement('premium_dark_matter', $price);

            if ($paid !== 1) {
                return 'tr_no_enough_dark_matter';
            }

            DB::table('import_exports')->where('user_id', $userId)->where('day', $day)->update([
                'item' => $this->drawItem($tier),
                'swaps' => $row['swaps'] + 1,
            ]);

            return null;
        });
    }

    /**
     * Buy the container with a resource of the planet: the item goes to the
     * inventory. Error key, or null.
     */
    public function buy(int $userId, int $planetId, string $resource, string $day): ?string
    {
        if (!isset(self::RATES[$resource])) {
            return 'tr_select_resource';
        }

        $cost = $this->cost($this->container($day)['price'], $resource);

        return DB::transaction(function () use ($userId, $planetId, $resource, $day, $cost): ?string {
            $row = $this->row($userId, $day);

            if ($row['bought']) {
                return 'tr_ie_already_bought';
            }

            $paid = DB::table('planets')
                ->where('planet_id', $planetId)
                ->where('planet_user_id', $userId)
                ->where('planet_' . $resource, '>=', $cost)
                ->decrement('planet_' . $resource, $cost);

            if ($paid !== 1) {
                return 'tr_ie_not_enough';
            }

            DB::table('import_exports')->where('user_id', $userId)->where('day', $day)->update(['bought' => true]);
            $this->items->add($userId, $row['item']);

            return null;
        });
    }

    /**
     * The player's row of the day (locked within a transaction), created
     * with a drawn item; older days go.
     *
     * @return array{item: string, swaps: int, bought: bool}
     */
    private function row(int $userId, string $day): array
    {
        $query = fn () => DB::table('import_exports')->where('user_id', $userId)->where('day', $day)->lockForUpdate()->first();
        $row = $query();

        if ($row === null) {
            DB::table('import_exports')->where('user_id', $userId)->where('day', '<', $day)->delete();
            DB::table('import_exports')->insertOrIgnore([
                'user_id' => $userId,
                'day' => $day,
                'item' => $this->drawItem($this->container($day)['tier']),
            ]);
            $row = $query();
        }

        $row = (array) $row;

        return [
            'item' => is_string($row['item'] ?? null) ? $row['item'] : '',
            'swaps' => is_numeric($row['swaps'] ?? null) ? (int) $row['swaps'] : 0,
            'bought' => (bool) ($row['bought'] ?? false),
        ];
    }

    private function drawItem(string $tier): string
    {
        $items = array_keys($this->catalog->tradable($tier));

        return $items[array_rand($items)] ?? '';
    }

    /**
     * A number between $min and $max that is the same for the same seed
     * (the container of a day is the same for all players).
     */
    private static function roll(string $seed, int | float $min, int | float $max): int
    {
        $min = (int) $min;
        $max = (int) $max;

        return $min + (int) (hexdec(substr(hash('sha256', 'import-export-' . $seed), 0, 8)) % ($max - $min + 1));
    }
}
