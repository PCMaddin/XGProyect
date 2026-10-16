<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Libraries\Functions;
use App\Libraries\Users;
use App\Services\FormatService;
use App\Services\Game\Items\Item;
use App\Services\Game\Items\ItemCatalog;
use App\Services\Game\Items\ItemService;
use App\Services\Game\Items\ItemTexts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Items: the player's inventory (used on the current planet or moon) and
 * the shop that sells boosters and field items for dark matter.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class ItemsController extends BaseController
{
    private const REDIRECT_TARGET = 'game.php?page=items';

    public function __construct(
        private ItemService $items,
        private ItemCatalog $catalog,
        private ItemTexts $texts,
        private FormatService $formatService,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Officers));

        $userId = $this->int(Users::getInstance()->getUserData(), 'id');
        $planetId = $this->int(Users::getInstance()->getPlanetData(), 'planet_id');
        $error = '';

        if ($request->isMethod('post')) {
            $key = $request->string('item')->toString();
            $result = match (true) {
                $request->has('buy') => $this->items->buy($userId, $key),
                $request->has('use') => $this->items->use($userId, $planetId, $key, time()),
                default => 'it_unknown_item',
            };

            if ($result === null) {
                return redirect(self::REDIRECT_TARGET)->with(
                    'items_success',
                    sprintf((string) __('game/items.' . ($request->has('buy') ? 'it_bought' : 'it_used')), $this->texts->name($key))
                );
            }

            $error = (string) __('game/items.' . $result);
        }

        $success = session('items_success');

        return view('items.view', [
            'color' => $error !== '' ? '#ff0000' : (is_string($success) ? 'lime' : ''),
            'message' => $error !== '' ? $error : (is_string($success) ? $success : ''),
            'inventory' => $this->inventory($userId),
            'boosters' => $this->boosters($planetId),
            'shop' => array_map(fn (Item $item): array => $this->describe($item) + [
                'price' => $this->formatService->prettyNumber((int) $item->price),
            ], array_values($this->catalog->shop())),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inventory(int $userId): array
    {
        $inventory = [];

        foreach ($this->items->inventory($userId) as $key => $amount) {
            $item = $this->catalog->get($key);

            if ($item !== null) {
                $inventory[] = $this->describe($item) + ['amount' => $amount];
            }
        }

        return $inventory;
    }

    /**
     * @return list<array<string, string>>
     */
    private function boosters(int $planetId): array
    {
        $boosters = [];

        foreach ($this->items->activeBoosters($planetId, time()) as $resource => $booster) {
            $boosters[] = [
                'text' => sprintf(
                    (string) __('game/items.it_booster_running'),
                    __('game/global.' . $resource),
                    $booster->percent,
                    date('d.m.Y H:i', $booster->ends_at)
                ),
            ];
        }

        return $boosters;
    }

    /**
     * @return array<string, string>
     */
    private function describe(Item $item): array
    {
        return [
            'key' => $item->key,
            'name' => $this->texts->name($item->key),
            'tier' => $item->tier,
            'description' => $this->texts->description($item->key),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
