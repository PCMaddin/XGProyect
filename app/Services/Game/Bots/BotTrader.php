<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\Game\DevelopmentDataService;
use App\Services\Game\Items\ItemCatalog;
use App\Services\Game\Items\ItemService;
use App\Services\Game\MerchantService;
use App\Services\Game\ScrapMerchantService;
use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A bot at the trader, once per turn, with the stock of its home planet and
 * through the same services as the trader pages:
 *
 * - it uses the items it owns (boosters, kraken, detroid, newtron) on the
 *   home planet;
 * - stuck because its next building or research lacks a resource its mines
 *   would need more than 6 hours for, it trades its surplus with the
 *   merchant: an offer it has (from an expedition) if he buys a resource
 *   it has spare, otherwise it calls him for dark matter to buy its largest
 *   surplus, if half of it pays the whole shortfall even at his worst rate
 *   and the last call is 6 hours ago;
 * - with a large surplus, it goes to the auctioneer and to import/export
 *   ({@see BotBidder}).
 *
 * The numbers are in {@see BotTradeRules}. The scrap merchant is left out: he
 * pays only a share of the price for ships and defences, which the bots
 * build for a purpose.
 */
class BotTrader
{
    use ReadsMixedValues;

    /** seconds between two paid merchant calls */
    private const MERCHANT_GAP = BotTradeRules::WAIT_HOURS * 3600;

    public function __construct(
        private BotTradeRules $rules,
        private BotStrategy $strategy,
        private DevelopmentDataService $developmentData,
        private DevelopmentsService $developments,
        private MerchantService $merchant,
        private ScrapMerchantService $storage,
        private ItemCatalog $catalog,
        private ItemService $items,
        private BotBidder $bidder,
    ) {
    }

    /**
     * @param array<string, mixed> $user the bot's row
     */
    public function act(array $user, int $homePlanetId, int $now): void
    {
        $userId = self::asInt($user['id'] ?? 0);
        $planet = $this->planetRow($userId, $homePlanetId);

        if ($planet === []) {
            return;
        }

        $this->useItems($userId, $homePlanetId, $now);

        $need = $this->need($userId, $planet);
        $perHour = $this->resources($planet, '_perhour');

        $this->trade($userId, $this->planetRow($userId, $homePlanetId), $need, $perHour);
        $this->bidder->bid($userId, $homePlanetId, $this->surplus($userId, $homePlanetId, $need), $perHour, $now);
        $this->bidder->buyContainer($userId, $homePlanetId, $this->surplus($userId, $homePlanetId, $need), $perHour, $now);
    }

    /**
     * The stock above the need, read now.
     *
     * @param array<string, float> $need
     *
     * @return array<string, float>
     */
    private function surplus(int $userId, int $planetId, array $need): array
    {
        return $this->rules->balance($this->resources($this->planetRow($userId, $planetId), ''), $need)['surplus'];
    }

    /**
     * Each item kind once, as a player would click it; an item that cannot
     * work now (nothing building, another booster running) waits.
     */
    private function useItems(int $userId, int $planetId, int $now): void
    {
        foreach (array_keys($this->items->inventory($userId)) as $key) {
            if ($this->catalog->get($key)?->isTradable() === true) {
                $this->items->use($userId, $planetId, $key, $now);
            }
        }
    }

    /**
     * The merchant, when a shortfall is worth it: the offer the bot has,
     * else a call for its largest surplus.
     *
     * @param array<string, mixed> $planet
     * @param array<string, float> $need
     * @param array<string, float> $perHour
     */
    private function trade(int $userId, array $planet, array $need, array $perHour): void
    {
        ['surplus' => $surplus, 'shortfall' => $shortfall] = $this->rules->balance($this->resources($planet, ''), $need);

        if (!$this->rules->worthTrading($shortfall, $perHour)) {
            return;
        }

        $offer = $this->merchant->offer($userId);

        if ($offer !== null && $this->exchange($userId, $planet, $offer, $surplus, $shortfall)) {
            return;
        }

        $sell = $this->rules->sellResource($surplus);
        $key = 'bot-merchant:' . $userId;

        // half the surplus must pay the whole shortfall, even at his worst rate
        if ($sell === null ||
            !$this->rules->covers($this->purchase($planet, ['sell' => $sell, 'factor' => $this->merchant->lowestFactor()], $surplus, $shortfall), $shortfall, $sell) ||
            self::asInt(DB::table('premium')->where('premium_user_id', $userId)->value('premium_dark_matter')) < $this->merchant->price() ||
            RateLimiter::tooManyAttempts($key, 1) ||
            $this->merchant->call($userId, $sell) !== null) {
            return;
        }

        RateLimiter::hit($key, self::MERCHANT_GAP);
        $offer = $this->merchant->offer($userId);

        if ($offer !== null) {
            $this->exchange($userId, $planet, $offer, $surplus, $shortfall);
        }
    }

    /**
     * @param array<string, mixed>               $planet
     * @param array{sell: string, factor: float} $offer
     * @param array<string, float>               $surplus
     * @param array<string, float>               $shortfall
     */
    private function exchange(int $userId, array $planet, array $offer, array $surplus, array $shortfall): bool
    {
        $amounts = $this->purchase($planet, $offer, $surplus, $shortfall);

        return $amounts !== [] && $this->merchant->exchange($userId, self::asInt($planet['planet_id'] ?? 0), $amounts) === null;
    }

    /**
     * @param array<string, mixed>               $planet
     * @param array{sell: string, factor: float} $offer
     * @param array<string, float>               $surplus
     * @param array<string, float>               $shortfall
     *
     * @return array<string, int>
     */
    private function purchase(array $planet, array $offer, array $surplus, array $shortfall): array
    {
        $rates = [];
        $free = [];

        foreach (MerchantService::RESOURCES as $resource) {
            $rates[$resource] = $resource === $offer['sell'] ? 0.0 : $this->merchant->rate($offer, $resource);
            $free[$resource] = (float) $this->storage->freeStorage($planet, $resource);
        }

        return $this->rules->purchase($offer['sell'], $surplus[$offer['sell']] ?? 0.0, $shortfall, $free, $rates);
    }

    /**
     * What the home planet's next building and the next research cost,
     * those that are not under way.
     *
     * @param array<string, mixed> $planet
     *
     * @return array<string, float>
     */
    private function need(int $userId, array $planet): array
    {
        $planetId = self::asInt($planet['planet_id'] ?? 0);
        $research = (array) DB::table('research')->where('research_user_id', $userId)->first();
        $levels = $this->developmentData->levelsFromData($planet, $research);
        $researching = DB::table('research_queues')->where('user_id', $userId)->exists();
        $steps = [];

        if (!DB::table('building_queues')->where('planet_id', $planetId)->exists()) {
            $steps[] = $this->strategy->nextBuilding($levels, $this->numbers($planet), true, $researching);
        }

        if (!$researching && ($levels[Buildings::BUILDING_LABORATORY] ?? 0) > 0) {
            $steps[] = $this->strategy->nextResearch($levels);
        }

        $need = array_fill_keys(BotTradeRules::RESOURCES, 0.0);

        foreach (array_filter($steps, static fn (?int $step): bool => $step !== null) as $step) {
            foreach ($this->developments->developmentPrice($step, $levels[$step] ?? 0) as $resource => $amount) {
                if (isset($need[$resource])) {
                    $need[$resource] += (float) $amount;
                }
            }
        }

        return $need;
    }

    /**
     * Stock, storage capacity and energy as {@see BotStrategy} reads them.
     *
     * @param array<string, mixed> $planet
     *
     * @return array<string, float>
     */
    private function numbers(array $planet): array
    {
        $numbers = [];

        foreach (['planet_energy_max', 'planet_energy_used'] as $column) {
            $numbers[$column] = self::asFloat($planet[$column] ?? 0);
        }

        foreach (BotTradeRules::RESOURCES as $resource) {
            $numbers['planet_' . $resource] = self::asFloat($planet['planet_' . $resource] ?? 0);
            $numbers['planet_' . $resource . '_perhour'] = self::asFloat($planet['planet_' . $resource . '_perhour'] ?? 0);
            // the capacity, which the table does not keep: the room of the empty storage
            $numbers['planet_' . $resource . '_max'] = (float) $this->storage->freeStorage(['planet_' . $resource => 0] + $planet, $resource);
        }

        return $numbers;
    }

    /**
     * @param array<string, mixed> $planet
     * @param string               $suffix '' for the stock, '_perhour' for the production
     *
     * @return array<string, float>
     */
    private function resources(array $planet, string $suffix): array
    {
        $resources = [];

        foreach (BotTradeRules::RESOURCES as $resource) {
            $resources[$resource] = self::asFloat($planet['planet_' . $resource . $suffix] ?? 0);
        }

        return $resources;
    }

    /**
     * The planet with its buildings, read now.
     *
     * @return array<string, mixed>
     */
    private function planetRow(int $userId, int $planetId): array
    {
        $row = DB::table('planets AS p')
            ->join('buildings AS b', 'b.building_planet_id', '=', 'p.planet_id')
            ->where('p.planet_id', $planetId)
            ->where('p.planet_user_id', $userId)
            ->where('p.planet_destroyed', 0)
            ->first();

        return $row === null ? [] : (array) $row;
    }
}
