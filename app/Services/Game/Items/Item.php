<?php

declare(strict_types=1);

namespace App\Services\Game\Items;

/**
 * One kind of item (OGame "Items", since version 4.0).
 */
final class Item
{
    public const BOOSTER = 'booster';
    public const PLANET_FIELDS = 'planet_fields';
    public const MOON_FIELDS = 'moon_fields';
    public const KRAKEN = 'kraken';
    public const DETROID = 'detroid';
    public const NEWTRON = 'newtron';

    public const TIERS = ['bronze', 'silver', 'gold'];

    /**
     * @param string      $type     one of the constants above
     * @param int         $value    percent (booster), fields, or seconds (kraken, detroid, newtron)
     * @param int         $duration seconds a booster works
     * @param int|null    $price    dark matter in the shop, null when not sold there
     * @param string|null $resource the resource of a booster
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $tier,
        public readonly int $value,
        public readonly int $duration = 0,
        public readonly ?int $price = null,
        public readonly ?string $resource = null,
    ) {
    }

    /**
     * Whether the auctioneer, import/export and expeditions hand it out
     * (all but the field items).
     */
    public function isTradable(): bool
    {
        return !in_array($this->type, [self::PLANET_FIELDS, self::MOON_FIELDS], true);
    }

    /**
     * The language key of its name (the tier is added separately).
     */
    public function nameKey(): string
    {
        return $this->type === self::BOOSTER ? 'it_' . $this->resource . '_booster' : 'it_' . $this->type;
    }
}
