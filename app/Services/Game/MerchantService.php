<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Services\Game\Formulas\ProductionService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * The merchant who exchanges resources (trader, "exchange resources").
 *
 * Calling him costs dark matter (merchant_price). He then buys one resource,
 * the one the player chose, at rates drawn once per call: the base rates
 * (merchant_metal/crystal/deuterium_multiplier, 3/2/1 by default) times a
 * factor between merchant_base_min_exchange_rate and
 * merchant_base_max_exchange_rate. Selling metal at the factor 0.88 thus
 * gives 2 * 0.88 / 3 crystal and 1 * 0.88 / 3 deuterium per metal. The
 * offer is kept with the player (premium_merchant_offer) until one exchange
 * uses it, on any planet; received resources must fit into its storage.
 * An expedition can meet him: then he comes for free with a random
 * resource, or improves the rate of the current offer (never worsens it).
 */
class MerchantService
{
    public const RESOURCES = ['metal', 'crystal', 'deuterium'];

    private const STORAGES = [
        'metal' => 'building_metal_store',
        'crystal' => 'building_crystal_store',
        'deuterium' => 'building_deuterium_tank',
    ];

    public function __construct(
        private SettingsService $settings,
        private ProductionService $productionService,
    ) {
    }

    public function price(): int
    {
        return (int) $this->settings->getFloat('merchant_price');
    }

    /**
     * The player's current offer, if he called the merchant.
     *
     * @return array{sell: string, factor: float}|null
     */
    public function offer(int $userId): ?array
    {
        $raw = DB::table('premium')->where('premium_user_id', $userId)->value('premium_merchant_offer');
        $offer = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($offer) || !in_array($offer['sell'] ?? null, self::RESOURCES, true) || !is_numeric($offer['factor'] ?? null)) {
            return null;
        }

        return ['sell' => (string) $offer['sell'], 'factor' => (float) $offer['factor']];
    }

    /**
     * An expedition met the merchant: he comes for free to buy a random
     * resource; when the player already has an offer, he keeps its resource
     * and the better of both rates.
     */
    public function grantExpeditionOffer(int $userId): void
    {
        $current = $this->offer($userId);
        $factor = $this->drawFactor();
        $offer = $current === null
            ? ['sell' => self::RESOURCES[array_rand(self::RESOURCES)], 'factor' => $factor]
            : ['sell' => $current['sell'], 'factor' => max($current['factor'], $factor)];

        DB::table('premium')
            ->where('premium_user_id', $userId)
            ->update(['premium_merchant_offer' => json_encode($offer)]);
    }

    /**
     * Call the merchant to buy $sell: pays the dark matter and draws the
     * factor. Error language key, or null when he came.
     */
    public function call(int $userId, string $sell): ?string
    {
        if (!in_array($sell, self::RESOURCES, true)) {
            return 'tr_select_resource';
        }

        $offer = (string) json_encode(['sell' => $sell, 'factor' => $this->drawFactor()]);
        $price = $this->price();

        $updated = DB::table('premium')
            ->where('premium_user_id', $userId)
            ->where('premium_dark_matter', '>=', $price)
            ->update([
                'premium_dark_matter' => DB::raw('premium_dark_matter - ' . $price),
                'premium_merchant_offer' => $offer,
            ]);

        return $updated === 1 ? null : 'tr_no_enough_dark_matter';
    }

    /**
     * How much of $buy the merchant gives for one unit of the sold resource.
     *
     * @param array{sell: string, factor: float} $offer
     */
    public function rate(array $offer, string $buy): float
    {
        return $this->baseRate($buy) * $offer['factor'] / max($this->baseRate($offer['sell']), 0.000001);
    }

    /**
     * The amount of the sold resource the wanted amounts cost.
     *
     * @param array{sell: string, factor: float} $offer
     * @param array<string, int>                 $amounts resource => amount wanted
     */
    public function cost(array $offer, array $amounts): int
    {
        $cost = 0.0;

        foreach ($amounts as $buy => $amount) {
            $rate = $this->rate($offer, $buy);
            $cost += $rate > 0 ? $amount / $rate : 0;
        }

        return (int) ceil($cost);
    }

    /**
     * Exchange on the planet with the player's offer, which it uses up.
     * Error language key (with the resource concerned), or null when done.
     *
     * @param array<string, int> $amounts resource => amount wanted
     *
     * @return array{0: string, 1: string}|null
     */
    public function exchange(int $userId, int $planetId, array $amounts): ?array
    {
        $offer = $this->offer($userId);

        if ($offer === null) {
            return ['tr_no_merchant', ''];
        }

        $amounts = array_filter(
            array_intersect_key($amounts, array_flip(array_diff(self::RESOURCES, [$offer['sell']]))),
            fn (int $amount): bool => $amount > 0
        );

        if ($amounts === []) {
            return ['tr_nothing_to_exchange', ''];
        }

        $cost = $this->cost($offer, $amounts);

        return DB::transaction(function () use ($userId, $planetId, $offer, $amounts, $cost): ?array {
            $planet = DB::table('planets AS p')
                ->join('buildings AS b', 'b.building_planet_id', '=', 'p.planet_id')
                ->where('p.planet_id', $planetId)
                ->where('p.planet_user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($planet === null) {
                return ['tr_no_merchant', ''];
            }

            $row = (array) $planet;

            if ((float) $row['planet_' . $offer['sell']] < $cost) {
                return ['tr_not_enough_resources', $offer['sell']];
            }

            foreach ($amounts as $buy => $amount) {
                $storage = $this->productionService->maxStorable((int) $row[self::STORAGES[$buy]]);

                if ((float) $row['planet_' . $buy] + $amount > $storage) {
                    return ['tr_no_enough_storage_for', $buy];
                }
            }

            $changes = ['planet_' . $offer['sell'] => DB::raw('planet_' . $offer['sell'] . ' - ' . $cost)];

            foreach ($amounts as $buy => $amount) {
                $changes['planet_' . $buy] = DB::raw('planet_' . $buy . ' + ' . $amount);
            }

            DB::table('planets')->where('planet_id', $planetId)->update($changes);
            DB::table('premium')->where('premium_user_id', $userId)->update(['premium_merchant_offer' => null]);

            return null;
        });
    }

    private function baseRate(string $resource): float
    {
        return $this->settings->getFloat('merchant_' . $resource . '_multiplier');
    }

    private function drawFactor(): float
    {
        $min = $this->settings->getFloat('merchant_base_min_exchange_rate');
        $max = max($min, $this->settings->getFloat('merchant_base_max_exchange_rate'));

        return round($min + (mt_rand() / mt_getrandmax()) * ($max - $min), 2);
    }
}
