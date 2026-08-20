<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Libraries\Functions;
use App\Libraries\Game\ResourceMarket;
use Xgp\App\Libraries\Users;

/**
 * Resource market: refill the planet's resource storages with dark matter.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class TraderResourcesController extends BaseController
{
    use PreparesLegacySql;

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

    public function __construct(private FormatService $formatService)
    {
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

        $redirect = $this->handleRefill($request);

        if ($redirect !== null) {
            return $redirect;
        }

        return view('trader.overview', array_merge(
            $this->messageDisplay(),
            [
                'currentMode' => view('trader.resources', [
                    'resourcesList' => $this->buildResourcesSection(),
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
        DB::update(
            $this->prepareSql(
                'UPDATE `' . PREMIUM . '` pr, `' . PLANETS . '` p SET
                    pr.`premium_dark_matter` = pr.`premium_dark_matter` - ?,
                    p.`planet_' . $resource . '` = ?
                WHERE pr.`premium_user_id` = ? AND p.`planet_id` = ?;'
            ),
            [$darkMatter, $amount, $this->int($this->user, 'id'), $this->int($this->planet, 'planet_id')]
        );

        return redirect(self::REDIRECT_TARGET);
    }

    /**
     * @return array<string, string>
     */
    private function messageDisplay(): array
    {
        if ($this->error === '') {
            return ['color' => '', 'message' => ''];
        }

        return ['color' => '#ff0000', 'message' => $this->error];
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
