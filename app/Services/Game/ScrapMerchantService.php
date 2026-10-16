<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\GameObjects\GameObjectInterface;
use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Game\Formulas\ProductionService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * The scrap merchant (trader, "scrap merchant"), as in OGame.
 *
 * He buys ships and defense systems standing on the planet for a share of
 * their building price, paid in the same resources: merchant_scrap_base_rate
 * percent (35 by default). Bargaining raises the share by 5 to 14 points, up
 * to merchant_scrap_max_rate (75). The first bargain costs
 * merchant_scrap_bargain_price dark matter (2.000), each further one that
 * much more, at most five times as much (10.000). Rate and bargains are kept
 * with the player (premium_scrap_rate, premium_scrap_bargains) until the
 * next sale, on any planet. He pays only what fits into the storages; the
 * missiles are no scrap.
 */
class ScrapMerchantService
{
    /** ships and defenses he buys; the missiles (500+) are no scrap */
    private const LAST_UNIT_ID = 499;

    private const BARGAIN_MIN_STEP = 5;
    private const BARGAIN_MAX_STEP = 14;

    /** the price of a bargain grows up to this many times the first one */
    private const BARGAIN_MAX_PRICE_FACTOR = 5;

    private const STORAGES = [
        'metal' => 'building_metal_store',
        'crystal' => 'building_crystal_store',
        'deuterium' => 'building_deuterium_tank',
    ];

    public function __construct(
        private SettingsService $settings,
        private GameObjectRegistry $registry,
        private ProductionService $productionService,
    ) {
    }

    /**
     * The ships and defense systems he buys, by id.
     *
     * @return array<int, GameObjectInterface>
     */
    public function units(): array
    {
        return $this->registry->ships()
            ->merge($this->registry->defenses())
            ->filter(fn (GameObjectInterface $unit): bool => $unit->getId() <= self::LAST_UNIT_ID)
            ->mapWithKeys(fn (GameObjectInterface $unit): array => [$unit->getId() => $unit])
            ->all();
    }

    public function baseRate(): int
    {
        return max(0, min(100, $this->settings->getInt('merchant_scrap_base_rate')));
    }

    public function maxRate(): int
    {
        return max($this->baseRate(), min(100, $this->settings->getInt('merchant_scrap_max_rate')));
    }

    /**
     * What the player's next bargain costs: the first one the set price,
     * each further one that much more, at most five times as much.
     */
    public function bargainPrice(int $userId): int
    {
        $bargains = DB::table('premium')->where('premium_user_id', $userId)->value('premium_scrap_bargains');
        $factor = min(self::BARGAIN_MAX_PRICE_FACTOR, (is_numeric($bargains) ? (int) $bargains : 0) + 1);

        return max(0, $this->settings->getInt('merchant_scrap_bargain_price')) * $factor;
    }

    /**
     * The share of the building price (percent) he pays the player now.
     */
    public function rate(int $userId): int
    {
        $rate = DB::table('premium')->where('premium_user_id', $userId)->value('premium_scrap_rate');

        return is_numeric($rate) ? max($this->baseRate(), min($this->maxRate(), (int) $rate)) : $this->baseRate();
    }

    /**
     * Bargain: pays the dark matter and raises the rate. Error language key,
     * or null when he gave in.
     */
    public function bargain(int $userId): ?string
    {
        $rate = $this->rate($userId);

        if ($rate >= $this->maxRate()) {
            return 'tr_scrap_final';
        }

        $price = $this->bargainPrice($userId);
        $newRate = min($this->maxRate(), $rate + random_int(self::BARGAIN_MIN_STEP, self::BARGAIN_MAX_STEP));

        $updated = DB::table('premium')
            ->where('premium_user_id', $userId)
            ->where('premium_dark_matter', '>=', $price)
            ->update([
                'premium_dark_matter' => DB::raw('premium_dark_matter - ' . $price),
                'premium_scrap_rate' => $newRate,
                'premium_scrap_bargains' => DB::raw('COALESCE(premium_scrap_bargains, 0) + 1'),
            ]);

        return $updated === 1 ? null : 'tr_no_enough_dark_matter';
    }

    /**
     * What he pays for the units at the rate.
     *
     * @param array<int, int> $amounts unit id => amount
     *
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    public function payment(array $amounts, int $rate): array
    {
        $units = $this->units();
        $payment = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];

        foreach ($amounts as $id => $amount) {
            if (!isset($units[$id]) || $amount <= 0) {
                continue;
            }

            $price = $units[$id]->getPrice();
            $payment['metal'] += $price->getMetal() * $amount;
            $payment['crystal'] += $price->getCrystal() * $amount;
            $payment['deuterium'] += $price->getDeuterium() * $amount;
        }

        return array_map(fn (float $total): int => (int) floor($total * $rate / 100), $payment);
    }

    /**
     * Sell the units of the planet at the player's rate, which goes back to
     * the base rate. Error language key (with the unit or resource
     * concerned), or the resources received.
     *
     * @param array<int, int> $amounts unit id => amount
     *
     * @return array{error: array{0: string, 1: string}}|array{payment: array{metal: int, crystal: int, deuterium: int}}
     */
    public function scrap(int $userId, int $planetId, array $amounts): array
    {
        $units = $this->units();
        $amounts = array_filter(
            array_intersect_key($amounts, $units),
            fn (int $amount): bool => $amount > 0
        );

        if ($amounts === []) {
            return ['error' => ['tr_scrap_nothing_selected', '']];
        }

        $rate = $this->rate($userId);

        return DB::transaction(function () use ($userId, $planetId, $amounts, $units, $rate): array {
            $planet = DB::table('planets AS p')
                ->join('ships AS s', 's.ship_planet_id', '=', 'p.planet_id')
                ->join('defenses AS d', 'd.defense_planet_id', '=', 'p.planet_id')
                ->join('buildings AS b', 'b.building_planet_id', '=', 'p.planet_id')
                ->where('p.planet_id', $planetId)
                ->where('p.planet_user_id', $userId)
                ->where('p.planet_destroyed', 0)
                ->lockForUpdate()
                ->first();

            if ($planet === null) {
                return ['error' => ['tr_scrap_nothing_selected', '']];
            }

            $row = (array) $planet;
            $changes = ['ships' => [], 'defenses' => []];

            foreach ($amounts as $id => $amount) {
                $column = $units[$id]->getName();

                if (!is_numeric($row[$column] ?? null) || (int) $row[$column] < $amount) {
                    return ['error' => ['tr_scrap_not_enough', $column]];
                }

                $table = str_starts_with($column, 'ship_') ? 'ships' : 'defenses';
                $changes[$table][$column] = DB::raw('`' . $column . '` - ' . $amount);
            }

            $payment = $this->payment($amounts, $rate);

            foreach ($payment as $resource => $amount) {
                if ($amount > $this->freeStorage($row, $resource)) {
                    return ['error' => ['tr_no_enough_storage_for', $resource]];
                }
            }

            foreach (array_filter($changes) as $table => $columns) {
                DB::table($table)->where($table === 'ships' ? 'ship_planet_id' : 'defense_planet_id', $planetId)->update($columns);
            }

            DB::table('planets')->where('planet_id', $planetId)->update([
                'planet_metal' => DB::raw('planet_metal + ' . $payment['metal']),
                'planet_crystal' => DB::raw('planet_crystal + ' . $payment['crystal']),
                'planet_deuterium' => DB::raw('planet_deuterium + ' . $payment['deuterium']),
            ]);
            DB::table('premium')->where('premium_user_id', $userId)->update(['premium_scrap_rate' => null, 'premium_scrap_bargains' => 0]);

            return ['payment' => $payment];
        });
    }

    /**
     * Room left in the planet's storage for the resource.
     *
     * @param array<string, mixed> $planet the planet with its buildings
     */
    public function freeStorage(array $planet, string $resource): int
    {
        $level = $planet[self::STORAGES[$resource]] ?? 0;
        $stored = $planet['planet_' . $resource] ?? 0;

        return max(0, $this->productionService->maxStorable(is_numeric($level) ? (int) $level : 0) - (int) floor(is_numeric($stored) ? (float) $stored : 0));
    }
}
