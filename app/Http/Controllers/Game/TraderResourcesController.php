<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\MerchantService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use App\Core\Concerns\PreparesSql;
use App\Libraries\Functions;
use App\Libraries\Game\ResourceMarket;
use App\Libraries\Users;

/**
 * Resource market: refill the planet's resource storages with dark matter.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class TraderResourcesController extends BaseController
{
    use PreparesSql;

    private const REDIRECT_TARGET = 'game.php?page=traderResources';

    /** @var list<string> */
    private const RESOURCES = ['metal', 'crystal', 'deuterium'];

    /** @var list<int> */
    private const PERCENTAGES = [10, 50, 100];

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    private ResourceMarket $trader;

    private string $error = '';

    private string $success = '';

    public function __construct(
        private FormatService $formatService,
        private MerchantService $merchant,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Trader));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();

        // Resolve through the container so ResourceMarket receives its
        // ProductionService dependency (the legacy `new ResourceMarket()`
        // call passed only two of the three constructor arguments).
        $this->trader = app()->make(ResourceMarket::class, [
            'user' => $this->user,
            'planet' => $this->planet,
        ]);

        $redirect = $this->handleRefill($request) ?? $this->handleMerchant($request);

        if ($redirect !== null) {
            return $redirect;
        }

        $this->success = $this->stringOf(session('trader_success'));

        return view('trader.overview', array_merge(
            $this->messageDisplay(),
            [
                'currentMode' => view('trader.resources', [
                    'resourcesList' => $this->buildResourcesSection(),
                    'exchangePrice' => $this->formatService->prettyNumber($this->merchant->price()),
                    'merchant' => $this->merchantSection(),
                ])->render(),
            ]
        ));
    }

    private function handleRefill(Request $request): ?RedirectResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->post();

        foreach (array_keys($payload) as $key) {
            $parsed = $this->parseRefillKey((string) $key);

            if ($parsed !== null) {
                return $this->refillResource($parsed[0], $parsed[1]);
            }
        }

        return null;
    }

    private function refillResource(string $resource, int $percentage): ?RedirectResponse
    {
        if (!$this->storageFillable($resource, $percentage)) {
            $this->error = (string) __('game/trader.tr_no_enough_storage');

            return null;
        }

        if (!$this->trader->isRefillPayable($resource, $percentage)) {
            $this->error = (string) __('game/trader.tr_no_enough_dark_matter');

            return null;
        }

        $darkMatter = (int) $this->priceToFill($resource, $percentage);
        $amount = $this->trader->getProjectedResouces($resource, $percentage);

        // $resource is whitelisted by parseRefillKey; the amounts are bound.
        // Paid only if the dark matter is still there: two requests at once
        // must not both pay from the same balance.
        $changed = DB::update(
            $this->prepareSql(
                'UPDATE `' . PREMIUM . '` pr, `' . PLANETS . '` p SET
                    pr.`premium_dark_matter` = pr.`premium_dark_matter` - ?,
                    p.`planet_' . $resource . '` = p.`planet_' . $resource . '` + ?
                WHERE pr.`premium_user_id` = ? AND p.`planet_id` = ? AND pr.`premium_dark_matter` >= ?;'
            ),
            // added, never set: cargo that left meanwhile must stay gone
            [$darkMatter, max(0, $amount - $this->int($this->planet, 'planet_' . $resource)), $this->int($this->user, 'id'), $this->int($this->planet, 'planet_id'), $darkMatter]
        );

        if ($changed === 0) {
            $this->error = (string) __('game/trader.tr_no_enough_dark_matter');

            return null;
        }

        return redirect(self::REDIRECT_TARGET);
    }

    /**
     * Call the merchant or exchange with him (POST only).
     */
    private function handleMerchant(Request $request): ?RedirectResponse
    {
        if (!$request->isMethod('post')) {
            return null;
        }

        $userId = $this->int($this->user, 'id');

        if ($request->has('call')) {
            $error = $this->merchant->call($userId, $this->stringOf($request->post('sell')));

            return $this->merchantResult($error === null ? null : [$error, ''], 'tr_merchant_arrived');
        }

        if ($request->has('exchange')) {
            $amounts = [];

            foreach (MerchantService::RESOURCES as $resource) {
                $amounts[$resource] = max(0, $request->integer('get_' . $resource));
            }

            return $this->merchantResult(
                $this->merchant->exchange($userId, $this->int($this->planet, 'planet_id'), $amounts),
                'tr_exchange_done'
            );
        }

        return null;
    }

    /**
     * @param array{0: string, 1: string}|null $error language key and resource
     */
    private function merchantResult(?array $error, string $successKey): ?RedirectResponse
    {
        if ($error !== null) {
            $resource = $error[1] !== '' ? (string) __('game/global.' . $error[1]) : '';
            $this->error = sprintf((string) __('game/trader.' . $error[0]), $resource);

            return null;
        }

        return redirect(self::REDIRECT_TARGET)->with('trader_success', (string) __('game/trader.' . $successKey));
    }

    /**
     * The exchange part of the page: the offer when the merchant is there.
     *
     * @return array<string, mixed>
     */
    private function merchantSection(): array
    {
        $offer = $this->merchant->offer($this->int($this->user, 'id'));

        if ($offer === null) {
            return ['offer' => false];
        }

        $sellName = (string) __('game/global.' . $offer['sell']);
        $buy = [];

        foreach (array_diff(MerchantService::RESOURCES, [$offer['sell']]) as $resource) {
            $rate = $this->merchant->rate($offer, $resource);
            $name = (string) __('game/global.' . $resource);
            $free = max(0, $this->trader->freeStorage($resource));

            $buy[] = [
                'resource' => $resource,
                'name' => $name,
                'rate' => round($rate, 6),
                'rateText' => sprintf((string) __('game/trader.tr_rate'), $sellName, number_format($rate, 2, ',', '.'), $name),
                'free' => $this->formatService->prettyNumber($free),
            ];
        }

        return [
            'offer' => true,
            'offerText' => sprintf((string) __('game/trader.tr_offer'), $sellName),
            'sellName' => $sellName,
            'available' => $this->formatService->prettyNumber($this->float($this->planet, 'planet_' . $offer['sell'])),
            'buy' => $buy,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messageDisplay(): array
    {
        if ($this->error !== '') {
            return ['color' => '#ff0000', 'message' => $this->error];
        }

        if ($this->success !== '') {
            return ['color' => 'lime', 'message' => $this->success];
        }

        return ['color' => '', 'message' => ''];
    }

    private function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildResourcesSection(): array
    {
        $resourcesList = [];

        foreach (self::RESOURCES as $resource) {
            $resourcesList[] = [
                'resource' => $resource,
                'resourceName' => __('game/global.' . $resource),
                'currentResource' => $this->formatService->shortlyNumber($this->float($this->planet, 'planet_' . $resource)),
                'maxResource' => $this->formatService->shortlyNumber($this->float($this->planet, 'planet_' . $resource . '_max')),
                'refillOptions' => $this->refillOptions($resource),
            ];
        }

        return $resourcesList;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function refillOptions(string $resource): array
    {
        $options = [];

        foreach (self::PERCENTAGES as $percentage) {
            $price = $this->priceToFill($resource, $percentage);

            $fillable = $this->storageFillable($resource, $percentage) && $price !== 0.0;
            $priceLabel = $this->formatService->colorRed('-');
            $button = '';

            if ($fillable) {
                $priceLabel = $this->formatService->customColor(
                    $this->formatService->prettyNumber((int) $price),
                    '#2cbef2'
                ) . ' ' . __('game/global.dark_matter_short');
                $button = '<input type="submit" name="' . $resource . '-' . $percentage . '" value="'
                    . __('game/trader.tr_refill_button') . '">';
            }

            $options[] = [
                // The legacy code compared the whole PERCENTAGES array to 100,
                // so it always used "refill by"; compare the value instead.
                'label' => $percentage === 100 ? __('game/trader.tr_refill_to') : __('game/trader.tr_refill_by'),
                'percentage' => $percentage,
                'tr_requires' => __('game/trader.tr_requires'),
                'price' => $priceLabel,
                'button' => $button,
            ];
        }

        return $options;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private function parseRefillKey(string $key): ?array
    {
        $pattern = '/^(' . implode('|', self::RESOURCES) . ')-(' . implode('|', self::PERCENTAGES) . ')$/';

        if (preg_match($pattern, $key, $matches) === 1) {
            return [$matches[1], (int) $matches[2]];
        }

        return null;
    }

    private function storageFillable(string $resource, int $percentage): bool
    {
        return match ($resource) {
            'metal' => $this->trader->isMetalStorageFillable($percentage),
            'crystal' => $this->trader->isCrystalStorageFillable($percentage),
            'deuterium' => $this->trader->isDeuteriumStorageFillable($percentage),
            default => false,
        };
    }

    private function priceToFill(string $resource, int $percentage): float
    {
        return match ($percentage) {
            10 => $this->trader->getPriceToFill10Percent($resource),
            50 => $this->trader->getPriceToFill50Percent($resource),
            100 => $this->trader->getPriceToFill100Percent($resource),
            default => 0.0,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function float(array $row, string $key): float
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
