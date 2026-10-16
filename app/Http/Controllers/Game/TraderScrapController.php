<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Libraries\Functions;
use App\Libraries\Users;
use App\Services\FormatService;
use App\Services\Game\ScrapMerchantService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Scrap merchant: sell ships and defense systems of the planet for a share
 * of their price, bargain for a better share with dark matter.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class TraderScrapController extends BaseController
{
    private const REDIRECT_TARGET = 'game.php?page=traderScrap';

    /** the merchant's haggling phrases, tr_scrap_phrase_1 … */
    private const PHRASES = 11;

    public function __construct(
        private ScrapMerchantService $scrapMerchant,
        private FormatService $formatService,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Trader));

        $userId = $this->int(Users::getInstance()->getUserData(), 'id');
        $planet = Users::getInstance()->getPlanetData();
        $error = '';

        if ($request->isMethod('post')) {
            $result = $this->handlePost($request, $userId, $this->int($planet, 'planet_id'));

            if ($result instanceof RedirectResponse) {
                return $result;
            }

            $error = $result;
        }

        $success = session('trader_success');

        return view('trader.overview', [
            'color' => $error !== '' ? '#ff0000' : (is_string($success) ? 'lime' : ''),
            'message' => $error !== '' ? $error : (is_string($success) ? $success : ''),
            'currentMode' => view('trader.scrap', $this->scrapSection($userId, $planet))->render(),
        ]);
    }

    /**
     * Bargain or sell. The redirect when done, else the error message.
     */
    private function handlePost(Request $request, int $userId, int $planetId): RedirectResponse | string
    {
        if ($request->has('bargain')) {
            $result = $this->scrapMerchant->bargain($userId);

            return $result === null
                ? redirect(self::REDIRECT_TARGET)->with('scrap_phrase', (string) __('game/trader.tr_scrap_phrase_' . random_int(1, self::PHRASES)))
                : (string) __('game/trader.' . $result);
        }

        if (!$request->has('scrap')) {
            return '';
        }

        $amounts = [];

        foreach (array_keys($this->scrapMerchant->units()) as $id) {
            $amounts[$id] = max(0, $request->integer('scrap_' . $id));
        }

        $result = $this->scrapMerchant->scrap($userId, $planetId, $amounts);

        if (isset($result['payment'])) {
            return redirect(self::REDIRECT_TARGET)->with('trader_success', $this->paymentText($result['payment']));
        }

        return sprintf((string) __('game/trader.' . $result['error'][0]), $this->unitName($result['error'][1]) ?: (string) __('game/global.' . $result['error'][1]));
    }

    /**
     * @param array<string, mixed> $planet
     *
     * @return array<string, mixed>
     */
    private function scrapSection(int $userId, array $planet): array
    {
        $rate = $this->scrapMerchant->rate($userId);
        $atMax = $rate >= $this->scrapMerchant->maxRate();
        $phrase = session('scrap_phrase');
        $units = [];

        foreach ($this->scrapMerchant->units() as $id => $unit) {
            $available = $this->int($planet, $unit->getName());

            if ($available <= 0) {
                continue;
            }

            $payment = $this->scrapMerchant->payment([$id => 1], $rate);

            $units[] = [
                'id' => $id,
                'name' => $this->unitName($unit->getName()),
                'available' => $available,
                'availableText' => $this->formatService->prettyNumber($available),
                'payment' => $payment,
            ];
        }

        return [
            'phrase' => match (true) {
                $atMax => (string) __('game/trader.tr_scrap_final'),
                is_string($phrase) => $phrase,
                default => (string) __('game/trader.tr_scrap_hello'),
            },
            'offer' => sprintf((string) __('game/trader.tr_scrap_offer'), $rate),
            'canBargain' => !$atMax,
            'bargainPrice' => sprintf(
                (string) __('game/trader.tr_scrap_bargain_price'),
                $this->formatService->prettyNumber($this->scrapMerchant->bargainPrice($userId))
            ),
            'freeStorage' => array_map(
                fn (string $resource): string => $this->formatService->prettyNumber($this->scrapMerchant->freeStorage($planet, $resource)),
                ['metal' => 'metal', 'crystal' => 'crystal', 'deuterium' => 'deuterium']
            ),
            'units' => $units,
        ];
    }

    /**
     * @param array{metal: int, crystal: int, deuterium: int} $payment
     */
    private function paymentText(array $payment): string
    {
        $parts = [];

        foreach ($payment as $resource => $amount) {
            $parts[] = $this->formatService->prettyNumber($amount) . ' ' . __('game/global.' . $resource);
        }

        return sprintf((string) __('game/trader.tr_scrap_done'), implode(', ', $parts));
    }

    private function unitName(string $column): string
    {
        return match (true) {
            str_starts_with($column, 'ship_') => (string) __('game/ships.' . $column),
            str_starts_with($column, 'defense_') => (string) __('game/defenses.' . $column),
            default => '',
        };
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
