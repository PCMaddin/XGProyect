<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\PremiumRequest;
use App\Models\DarkMatterOrder;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Premium\DarkMatterOrders;
use App\Services\Premium\DarkMatterShop;
use App\Services\SettingsService;
use App\Services\TimingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PremiumController extends AdminSettingsController
{
    /** The orders page shows the latest ones. */
    private const ORDERS_SHOWN = 200;

    public function __construct(
        SettingsService $settings,
        private DarkMatterShop $shop,
        private InstanceContext $context,
    ) {
        parent::__construct($settings);
    }

    public function index(): View
    {
        return $this->view('admin.premium', $this->buildViewData());
    }

    public function update(PremiumRequest $request): RedirectResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        // a universe of several gets its mode from the command line, which
        // keeps the list of universes in step
        if (!$this->modeEditable()) {
            unset($validated['premium_mode']);
        }

        foreach ($validated as $field => $value) {
            if ($value !== null) {
                $this->settings->write($field, $value);
            }
        }

        return $this->saved('admin.premium', 'admin/premium.pr_all_ok_message');
    }

    /**
     * The dark matter bought in this universe.
     */
    public function orders(DarkMatterOrders $orders, TimingService $timing): View
    {
        $revenue = [];

        foreach ($orders->revenue() as $currency => $cents) {
            $revenue[] = $this->shop->formatPrice($cents, $currency);
        }

        return $this->view('admin.premium_orders', [
            'orders' => $orders->latest(self::ORDERS_SHOWN)->map(fn (DarkMatterOrder $order): array => [
                'id' => $order->id,
                'user_id' => $order->user_id,
                'user_name' => $order->getAttribute('user_name'),
                'package' => $order->package,
                'dark_matter' => $order->dark_matter,
                'amount' => $this->shop->formatPrice($order->amount, $order->currency),
                'status' => $order->status,
                'created_at' => $timing->formatExtendedDate($order->created_at),
                'paid_at' => $order->paid_at === null ? '' : $timing->formatExtendedDate($order->paid_at),
            ])->all(),
            'revenue' => $revenue,
            'shown' => self::ORDERS_SHOWN,
        ]);
    }

    private function modeEditable(): bool
    {
        return $this->context->current() === null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(): array
    {
        $mode = $this->shop->isPay2Win() ? Instance::PAY2WIN : Instance::PLAY2WIN;

        return [
            'premium_url' => $this->settings->getString('premium_url'),
            'premium_mode' => $mode,
            'premium_mode_editable' => $this->modeEditable(),
            'shop_status' => match (true) {
                $this->shop->isOpen() => 'open',
                $mode === Instance::PLAY2WIN => 'closed_play2win',
                default => 'closed_stripe',
            },
            'registration_dark_matter' => $this->settings->getInt('registration_dark_matter'),
            'merchant_price' => $this->settings->getFloat('merchant_price'),
            'merchant_base_min_exchange_rate' => $this->settings->getFloat('merchant_base_min_exchange_rate'),
            'merchant_base_max_exchange_rate' => $this->settings->getFloat('merchant_base_max_exchange_rate'),
            'merchant_metal_multiplier' => $this->settings->getFloat('merchant_metal_multiplier'),
            'merchant_crystal_multiplier' => $this->settings->getFloat('merchant_crystal_multiplier'),
            'merchant_deuterium_multiplier' => $this->settings->getFloat('merchant_deuterium_multiplier'),
            'merchant_scrap_base_rate' => $this->settings->getInt('merchant_scrap_base_rate'),
            'merchant_scrap_max_rate' => $this->settings->getInt('merchant_scrap_max_rate'),
            'merchant_scrap_bargain_price' => $this->settings->getInt('merchant_scrap_bargain_price'),
        ];
    }
}
