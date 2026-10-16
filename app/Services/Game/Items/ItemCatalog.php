<?php

declare(strict_types=1);

namespace App\Services\Game\Items;

/**
 * The items of the original game (owiki.de, "Items"):
 *
 * - boosters raise the production of metal, crystal or deuterium on a
 *   planet by 10/20/30 % for a week (2.500/8.500/25.000 dark matter);
 * - planet fields add 4/9/15 fields to a planet (75.000/150.000/300.000),
 *   moon fields 2/4/6 to a moon (30.000/50.000/100.000), once each;
 * - kraken, detroid and newtron shorten the building, shipyard or research
 *   order by 30 minutes, 2 or 6 hours; only the auctioneer, import/export
 *   and expeditions hand them out, as the boosters;
 * - M.O.O.N.S. fires a moon shot at one of the player's planets without a
 *   moon: a moon with a chance of 20 %, without a debris field (the price
 *   of 50.000 dark matter is an assumption, the original's is not known).
 */
final class ItemCatalog
{
    private const WEEK = 7 * 86400;

    /** @var array<string, array<string, int>> */
    private const VALUES = [
        Item::BOOSTER => ['bronze' => 10, 'silver' => 20, 'gold' => 30],
        Item::PLANET_FIELDS => ['bronze' => 4, 'silver' => 9, 'gold' => 15],
        Item::MOON_FIELDS => ['bronze' => 2, 'silver' => 4, 'gold' => 6],
        Item::KRAKEN => ['bronze' => 1800, 'silver' => 7200, 'gold' => 21600],
        Item::DETROID => ['bronze' => 1800, 'silver' => 7200, 'gold' => 21600],
        Item::NEWTRON => ['bronze' => 1800, 'silver' => 7200, 'gold' => 21600],
        Item::MOONS => ['gold' => 20],
    ];

    /** @var array<string, array<string, int>> dark matter in the shop */
    private const PRICES = [
        Item::BOOSTER => ['bronze' => 2500, 'silver' => 8500, 'gold' => 25000],
        Item::PLANET_FIELDS => ['bronze' => 75000, 'silver' => 150000, 'gold' => 300000],
        Item::MOON_FIELDS => ['bronze' => 30000, 'silver' => 50000, 'gold' => 100000],
        Item::MOONS => ['gold' => 50000],
    ];

    private const RESOURCES = ['metal', 'crystal', 'deuterium'];

    /** @var array<string, Item>|null */
    private ?array $items = null;

    /**
     * @return array<string, Item>
     */
    public function all(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $items = [];

        foreach (self::VALUES as $type => $values) {
            foreach ($values as $tier => $value) {
                foreach ($type === Item::BOOSTER ? self::RESOURCES : [null] as $resource) {
                    $key = ($resource !== null ? $resource . '_' : '') . $type . '_' . $tier;
                    $items[$key] = new Item(
                        $key,
                        $type,
                        $tier,
                        $value,
                        $type === Item::BOOSTER ? self::WEEK : 0,
                        self::PRICES[$type][$tier] ?? null,
                        $resource,
                    );
                }
            }
        }

        return $this->items = $items;
    }

    public function get(string $key): ?Item
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The items the shop sells for dark matter.
     *
     * @return array<string, Item>
     */
    public function shop(): array
    {
        return array_filter($this->all(), fn (Item $item): bool => $item->price !== null);
    }

    /**
     * The items the auctioneer, import/export and expeditions hand out, of
     * one tier or of all.
     *
     * @return array<string, Item>
     */
    public function tradable(?string $tier = null): array
    {
        return array_filter(
            $this->all(),
            fn (Item $item): bool => $item->isTradable() && ($tier === null || $item->tier === $tier)
        );
    }
}
