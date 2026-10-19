<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\PremiumRequest;
use App\Models\DarkMatterOrder;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Premium\DarkMatterOrders;
use App\Services\Premium\DarkMatterShop;
use App\Services\Premium\PaymentMode;
use App\Services\Premium\TokenSpec;
use App\Services\Premium\WaxChain;
use App\Services\Premium\WaxPayments;
use App\Services\Premium\WaxRates;
use App\Services\SettingsService;
use App\Services\TimingService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;

/**
 * The premium settings (dark matter, merchant, ways to pay) and the orders
 * of the dark matter shop with the WAX transfers.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
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

        // unticked boxes send nothing: the hidden field tells the form was sent
        $methods = $validated['payment_methods'] ?? [];
        unset($validated['payment_methods'], $validated['payment_methods_form']);

        if ($request->has('payment_methods_form')) {
            $named = array_filter(is_array($methods) ? $methods : [], 'is_string');
            $this->settings->write('payment_methods', implode(',', DarkMatterShop::parseMethods(implode(',', $named))));
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
    public function orders(DarkMatterOrders $orders, WaxPayments $wax, TimingService $timing): View
    {
        $revenue = [];

        foreach ($orders->revenue() as $currency => $cents) {
            $revenue[] = $this->shop->formatPrice($cents, $currency);
        }

        foreach ($orders->tokenRevenue() as $symbol => $units) {
            $revenue[] = self::chainAmount($units, $symbol);
        }

        return $this->view('admin.premium_orders', [
            'orders' => $orders->latest(self::ORDERS_SHOWN)->map(fn (DarkMatterOrder $order): array => [
                'id' => $order->id,
                'user_id' => $order->user_id,
                'user_name' => $order->getAttribute('user_name'),
                'package' => $order->package,
                'dark_matter' => $order->dark_matter,
                // a token order costs tokens, never money
                'amount' => $order->provider === DarkMatterShop::TOKEN ? '' : $this->shop->formatPrice($order->amount, $order->currency),
                'provider' => $order->provider,
                'provider_amount' => $order->provider_amount === null ? '' : self::chainAmount($order->provider_amount, (string) $order->provider_currency),
                'memo' => in_array($order->provider, [DarkMatterShop::WAX, DarkMatterShop::TOKEN], true) ? (string) $order->provider_session : '',
                'status' => $order->status,
                'created_at' => $timing->formatExtendedDate($order->created_at),
                'paid_at' => $order->paid_at === null ? '' : $timing->formatExtendedDate($order->paid_at),
            ])->all(),
            'revenue' => $revenue,
            'shown' => self::ORDERS_SHOWN,
            'transfers' => $this->waxTransfers($wax, $timing),
        ]);
    }

    /**
     * The WAX transfers for this universe and those that fit none.
     *
     * @return list<array<string, string|int|null>>
     */
    private function waxTransfers(WaxPayments $wax, TimingService $timing): array
    {
        try {
            $transfers = $wax->latest($this->context->current()?->slug, self::ORDERS_SHOWN);
        } catch (QueryException) {
            // xgp:wax:check has not run yet: no table
            return [];
        }

        return array_values($transfers->map(static fn (object $row): array => [
            'time' => $timing->formatExtendedDate(self::int($row, 'block_time')),
            'from' => self::text($row, 'from_account'),
            'amount' => self::chainAmount(self::int($row, 'units'), self::text($row, 'symbol') ?: WaxChain::SYMBOL),
            'memo' => self::text($row, 'memo'),
            'order_id' => self::int($row, 'order_id') ?: null,
            'result' => self::text($row, 'result'),
            'trx_id' => self::text($row, 'trx_id'),
        ])->all());
    }

    /**
     * An amount on the chain in its token: WAX, or the game's own.
     */
    private static function chainAmount(int $units, string $symbol): string
    {
        $token = TokenSpec::game();

        return $token !== null && $symbol === $token->symbol ? $token->format($units) : WaxRates::format($units);
    }

    private static function int(object $row, string $field): int
    {
        $value = get_object_vars($row)[$field] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(object $row, string $field): string
    {
        $value = get_object_vars($row)[$field] ?? '';

        return is_scalar($value) ? (string) $value : '';
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
                default => 'closed_methods',
            },
            'payment_methods' => array_map(fn (string $method): array => [
                'name' => $method,
                'allowed' => in_array($method, $this->shop->allowedMethods(), true),
                'configured' => $this->shop->configured($method),
            ], DarkMatterShop::METHODS),
            'test_mode' => PaymentMode::testOnly(),
            'pays_for_test' => $this->shop->paysForTest(),
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
