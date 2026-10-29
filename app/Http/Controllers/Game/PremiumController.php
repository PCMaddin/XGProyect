<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\Premium\DarkMatterShop;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use App\Core\Concerns\PreparesSql;
use App\Core\Enumerators\OfficiersEnumerator as OE;
use App\Core\GameObjects\GameObjectRegistry;
use App\Libraries\Functions;
use App\Libraries\Users;

/**
 * Premium officers overview and purchase with dark matter.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PremiumController extends BaseController
{
    use PreparesSql;

    private const OFFICERS = [
        OE::PREMIUM_OFFICIER_COMMANDER,
        OE::PREMIUM_OFFICIER_ADMIRAL,
        OE::PREMIUM_OFFICIER_ENGINEER,
        OE::PREMIUM_OFFICIER_GEOLOGIST,
        OE::PREMIUM_OFFICIER_TECHNOCRAT,
    ];

    /** @var array<string, mixed> */
    private array $user = [];

    private GameObjectRegistry $registry;

    public function __construct(
        private FormatService $formatService,
        private OfficerService $officerService,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Officers));

        $this->user = Users::getInstance()->getUserData();
        $this->registry = app(GameObjectRegistry::class);

        $redirect = $this->handlePurchase($request);

        if ($redirect !== null) {
            return $redirect;
        }

        return view('premium.view', [
            // the shop of the universe, the operator's own (premium_url) or no button
            'premium_pay_url' => app(DarkMatterShop::class)->buyUrl(),
            'officier_list' => $this->buildOfficiersList(),
        ]);
    }

    private function handlePurchase(Request $request): ?RedirectResponse
    {
        // hiring costs dark matter: only by POST
        if (!$request->isMethod('post')) {
            return null;
        }

        $officer = $request->integer('offi');
        $time = is_string($raw = $request->query('time')) ? $raw : '';

        $officers = $this->registry->officers()->keys()->all();

        if (!in_array($officer, $officers, true) || !in_array($time, ['week', 'month'], true)) {
            return null;
        }

        $resource = 'darkmatter_' . $time;

        if (!$this->isOfficerAccessible($officer, $resource)) {
            return null;
        }

        $column = $this->officerColumn($officer);

        if ($column === '') {
            return null;
        }

        $duration = $time === 'month' ? ONE_MONTH * 3 : ONE_WEEK;
        $currentExpiry = $this->userInt($column);
        $expiry = $this->purchaseExpiry(
            $currentExpiry,
            $duration,
            time(),
            $this->officerService->isOfficerActive($currentExpiry, time())
        );

        $userId = $this->userInt('id');

        if ($userId > 0) {
            $price = $this->officerPrice($officer, $resource);

            // paid only from dark matter still there, and only for the term
            // read above: two requests at once must not both pay
            DB::update(
                $this->prepareSql(
                    'UPDATE `' . PREMIUM . '` SET
                        `premium_dark_matter` = `premium_dark_matter` - ?,
                        `' . $column . '` = ?
                    WHERE `premium_user_id` = ? AND `premium_dark_matter` >= ? AND COALESCE(`' . $column . '`, 0) = ?;'
                ),
                [$price, $expiry, $userId, $price, $currentExpiry]
            );
        }

        return redirect('game.php?page=premium');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildOfficiersList(): array
    {
        return array_map(fn (int $id): array => $this->setOfficier($id), self::OFFICERS);
    }

    /**
     * @return array<string, mixed>
     */
    private function setOfficier(int $officerId): array
    {
        return [
            'status' => $this->officerStatus($officerId),
            'name' => $this->officerText($officerId, 'name'),
            'description' => $this->officerText($officerId, 'description'),
            'benefits' => $this->officerText($officerId, 'benefits'),
            'month_price' => $this->formatService->prettyNumber($this->officerPrice($officerId, 'darkmatter_month')),
            'week_price' => $this->formatService->prettyNumber($this->officerPrice($officerId, 'darkmatter_week')),
            'img_big' => $this->officerImage($officerId, 'img_big'),
            'img_small' => $this->officerImage($officerId, 'img_small'),
            'link_month' => 'game.php?page=premium&offi=' . $officerId . '&time=month',
            'link_week' => 'game.php?page=premium&offi=' . $officerId . '&time=week',
        ];
    }

    private function officerStatus(int $officerId): string
    {
        $expiry = $this->userInt($this->officerColumn($officerId));

        if ($this->officerService->isOfficerActive($expiry, time())) {
            return $this->formatService->customColor(
                (string) $this->officerService->getDaysLeft($expiry, time()),
                'lime'
            );
        }

        return $this->formatService->colorRed((string) __('game/officier.of_inactive'));
    }

    /**
     * Premium time stacks on top of the current expiry when the officer is still
     * active, otherwise it starts from now.
     */
    private function purchaseExpiry(int $currentExpiry, int $duration, int $now, bool $active): int
    {
        return $active ? $currentExpiry + $duration : $now + $duration;
    }

    private function isOfficerAccessible(int $officer, string $resource): bool
    {
        return $this->officerPrice($officer, $resource) <= $this->userInt('premium_dark_matter');
    }

    /**
     * @param string $resource 'darkmatter_week' or 'darkmatter_month'
     */
    private function officerPrice(int $officer, string $resource): int
    {
        $object = $this->registry->findOfficer($officer);

        return match ($resource) {
            'darkmatter_week' => $object?->getDarkmatterWeek() ?? 0,
            'darkmatter_month' => $object?->getDarkmatterMonth() ?? 0,
            default => 0,
        };
    }

    /**
     * @param string $type 'img_big' or 'img_small'
     */
    private function officerImage(int $officer, string $type): string
    {
        $object = $this->registry->findOfficer($officer);

        return match ($type) {
            'img_big' => $object?->getImgBig() ?? '',
            'img_small' => $object?->getImgSmall() ?? '',
            default => '',
        };
    }

    private function officerColumn(int $officerId): string
    {
        return ($this->registry->names()[$officerId] ?? '');
    }

    private function officerText(int $officerId, string $key): string
    {
        $officers = trans('game/officier.officiers');

        if (
            is_array($officers) &&
            isset($officers[$officerId]) &&
            is_array($officers[$officerId]) &&
            isset($officers[$officerId][$key]) &&
            is_scalar($officers[$officerId][$key])
        ) {
            return (string) $officers[$officerId][$key];
        }

        return '';
    }

    private function userInt(string $key): int
    {
        $value = $this->user[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
