<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Libraries\Functions;
use App\Libraries\Users;
use App\Models\User;
use App\Services\FormatService;
use App\Services\Game\AuctionService;
use App\Services\Game\Items\ItemCatalog;
use App\Services\Game\Items\ItemTexts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * The auctioneer: bid resources for the item of the hour.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class TraderAuctioneerController extends BaseController
{
    private const REDIRECT_TARGET = 'game.php?page=traderAuctioneer';

    public function __construct(
        private AuctionService $auctions,
        private ItemCatalog $catalog,
        private ItemTexts $texts,
        private FormatService $formatService,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Trader));

        $userId = $this->int(Users::getInstance()->getUserData(), 'id');
        $planetId = $this->int(Users::getInstance()->getPlanetData(), 'planet_id');
        $now = time();
        $error = '';

        if ($request->isMethod('post') && $request->has('bid')) {
            $result = $this->auctions->bid($userId, $planetId, $request->string('resource')->toString(), max(0, $request->integer('amount')), $now);

            if ($result === null) {
                return redirect(self::REDIRECT_TARGET)->with('trader_success', (string) __('game/trader.tr_au_placed'));
            }

            $error = (string) __('game/trader.' . $result);
        }

        $success = session('trader_success');

        return view('trader.overview', [
            'color' => $error !== '' ? '#ff0000' : (is_string($success) ? 'lime' : ''),
            'message' => $error !== '' ? $error : (is_string($success) ? $success : ''),
            'currentMode' => view('trader.auctioneer', $this->section($userId, $now))->render(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function section(int $userId, int $now): array
    {
        $auction = $this->auctions->current($now);
        $next = date('H:i', $this->auctions->nextStart($now));

        if ($auction === null) {
            return ['auction' => false, 'next' => $next];
        }

        $item = $this->catalog->get($auction['item']);
        $highest = $this->auctions->highestBid($auction['id']);
        $running = $auction['ends_at'] > $now;

        return [
            'auction' => true,
            'running' => $running,
            'next' => $next,
            'tier' => $item->tier ?? 'bronze',
            'itemName' => $this->texts->name($auction['item']),
            'itemDescription' => $this->texts->description($auction['item']),
            'timeLeft' => intdiv($this->auctions->shownTimeLeft($auction['ends_at'], $now), 60),
            'highest' => $highest === null ? '' : sprintf(
                (string) __('game/trader.tr_au_highest'),
                $this->formatService->prettyNumber((int) $highest['value']),
                $this->playerName($highest['user_id'])
            ),
            'ownBid' => $this->formatService->prettyNumber((int) $this->auctions->bidOf($auction['id'], $userId)),
            'winner' => !$running && $highest !== null ? $this->playerName($highest['user_id']) : '',
        ];
    }

    private function playerName(int $userId): string
    {
        return (string) (User::find($userId)->name ?? '');
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
