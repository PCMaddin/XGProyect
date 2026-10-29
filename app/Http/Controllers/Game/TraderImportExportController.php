<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Libraries\Functions;
use App\Libraries\Users;
use App\Services\FormatService;
use App\Services\Game\ImportExportService;
use App\Services\Game\Items\ItemTexts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Import/export: the container of the day, bought with resources.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class TraderImportExportController extends BaseController
{
    private const REDIRECT_TARGET = 'game.php?page=traderImportExport';

    public function __construct(
        private ImportExportService $importExport,
        private ItemTexts $texts,
        private FormatService $formatService,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Trader));

        $userId = $this->int(Users::getInstance()->getUserData(), 'id');
        $planetId = $this->int(Users::getInstance()->getPlanetData(), 'planet_id');
        $day = date('Y-m-d');
        $error = '';

        if ($request->isMethod('post')) {
            $error = match (true) {
                $request->has('swap') => $this->importExport->swap($userId, $day),
                $request->has('buy') => $this->importExport->buy($userId, $planetId, $request->string('resource')->toString(), $day),
                default => null,
            };

            if ($error === null) {
                return redirect(self::REDIRECT_TARGET)->with('trader_success', (string) __('game/trader.' . ($request->has('swap') ? 'tr_ie_swapped' : 'tr_ie_bought')));
            }

            $error = (string) __('game/trader.' . $error);
        }

        $success = session('trader_success');

        return view('trader.overview', [
            'color' => $error !== '' ? '#ff0000' : (is_string($success) ? 'lime' : ''),
            'message' => $error !== '' ? $error : (is_string($success) ? $success : ''),
            'currentMode' => view('trader.importexport', $this->section($userId, $day))->render(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function section(int $userId, string $day): array
    {
        $container = $this->importExport->container($day);
        $state = $this->importExport->state($userId, $day);
        $costs = [];

        foreach (array_keys(ImportExportService::RATES) as $resource) {
            $costs[$resource] = $this->formatService->prettyNumber($this->importExport->cost($container['price'], $resource));
        }

        return [
            'tier' => $container['tier'],
            'tierName' => __('game/items.it_' . $container['tier']),
            'costs' => $costs,
            'bought' => $state['bought'],
            'boughtItem' => $state['bought'] ? $this->texts->name($state['item']) : '',
            'swapsLeft' => ImportExportService::MAX_SWAPS - $state['swaps'],
            'swapPrice' => $this->formatService->prettyNumber($this->importExport->swapPrice($container['tier'])),
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
