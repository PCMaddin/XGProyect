<?php

declare(strict_types=1);

namespace App\Services\Game\Items;

use App\Services\FormatService;

/**
 * Name and description of an item in the player's language.
 */
class ItemTexts
{
    public function __construct(
        private ItemCatalog $catalog,
        private FormatService $formatService,
    ) {
    }

    public function name(string $key): string
    {
        $item = $this->catalog->get($key);

        return $item === null
            ? ''
            : __('game/items.' . $item->nameKey()) . ' ' . __('game/items.it_' . $item->tier);
    }

    public function description(string $key): string
    {
        $item = $this->catalog->get($key);

        if ($item === null) {
            return '';
        }

        $value = in_array($item->type, [Item::KRAKEN, Item::DETROID, Item::NEWTRON], true)
            ? $this->formatService->prettyTime($item->value)
            : (string) $item->value;

        return sprintf(
            (string) __('game/items.it_desc_' . $item->type),
            $value,
            $item->resource !== null ? __('game/global.' . $item->resource) : ''
        );
    }
}
