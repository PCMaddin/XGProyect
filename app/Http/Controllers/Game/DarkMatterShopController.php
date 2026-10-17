<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Libraries\Functions;
use App\Models\DarkMatterOrder;
use App\Models\User;
use App\Services\FormatService;
use App\Services\TimingService;
use App\Services\Instances\InstanceContext;
use App\Services\Premium\DarkMatterOrders;
use App\Services\Premium\DarkMatterPackage;
use App\Services\Premium\DarkMatterShop;
use App\Services\Premium\StripeCheckout;
use App\Services\Premium\StripeException;
use App\Services\Premium\WaxChain;
use App\Services\Premium\WaxException;
use App\Services\Premium\WaxPayments;
use App\Services\Premium\WaxRates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use LogicException;

/**
 * game.php?page=darkmatter: the dark matter shop of a Pay2Win universe.
 * Lists the packages with the ways to pay the universe offers. Stripe: a
 * POST opens an order and sends the player to Stripe Checkout, which sends
 * them back to the order's page; the webhook credits the payment. WAX: a
 * POST opens an order with the price converted at the current rate and
 * shows what to transfer to which account with which memo; xgp:wax:check
 * credits the transfer. Closed (Play2Win or no way to pay): a message and
 * back to the premium page.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class DarkMatterShopController extends BaseController
{
    /** At most this many unfinished orders within the window below. */
    private const OPEN_ORDERS_LIMIT = 5;
    private const OPEN_ORDERS_WINDOW = 900;

    public function __construct(
        private DarkMatterShop $shop,
        private DarkMatterOrders $orders,
        private StripeCheckout $checkout,
        private WaxRates $rates,
        private InstanceContext $context,
        private FormatService $format,
        private TimingService $timing,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Officers));

        if (!$this->shop->isOpen()) {
            $this->message('dm_shop_closed', 'game.php?page=premium');
        }

        $user = $request->user();
        $userId = $user instanceof User ? (int) $user->id : 0;

        if ($request->isMethod('post')) {
            return $this->buy($request, $userId);
        }

        if ($request->query('order') !== null) {
            return $this->order($request, $userId);
        }

        $methods = $this->shop->methods();

        return view('premium.shop', [
            'methods' => $methods,
            'packages' => array_values(array_map(fn (DarkMatterPackage $package): array => [
                'id' => $package->id,
                'dark_matter' => $this->format->prettyNumber($package->darkMatter),
                'price' => $this->shop->formatPrice($package->price, $this->shop->currency()),
                'wax' => in_array(DarkMatterShop::WAX, $methods, true) ? $this->waxPrice($package->price) : '',
            ], $this->shop->packages())),
        ]);
    }

    private function buy(Request $request, int $userId): RedirectResponse
    {
        $id = $request->input('package');
        // only packages of the configuration: the price is never the player's
        $package = $this->shop->package(is_string($id) ? $id : '');
        $method = $request->input('method', DarkMatterShop::STRIPE);

        if ($package === null || $userId <= 0) {
            $this->message('dm_unknown_package', DarkMatterShop::PAGE);
        }

        if (!in_array($method, $this->shop->methods(), true)) {
            $this->message('dm_unknown_method', DarkMatterShop::PAGE);
        }

        $now = time();

        if ($this->orders->pendingSince($userId, $now - self::OPEN_ORDERS_WINDOW) >= self::OPEN_ORDERS_LIMIT) {
            $this->message('dm_too_many_orders', DarkMatterShop::PAGE);
        }

        if ($method === DarkMatterShop::WAX) {
            return $this->buyWithWax($userId, $package, $now);
        }

        $order = $this->orders->open($userId, $package, $this->shop->currency(), $now);
        $back = url('game.php') . '?page=darkmatter&order=' . $order->id;

        try {
            $session = $this->checkout->createSession(
                $order,
                (string) __('game/darkmatter.dm_package_name', ['amount' => $this->format->prettyNumber($package->darkMatter)]),
                $back,
                $back . '&cancelled=1',
                $this->context->current()->slug ?? ''
            );
        } catch (StripeException) {
            $this->orders->fail($order);
            $this->message('dm_checkout_failed', DarkMatterShop::PAGE);
        }

        $this->orders->attachSession($order, $session['id']);

        return redirect()->away($session['url'], 303);
    }

    /**
     * Opens a WAX order at the current rate and shows what to transfer.
     */
    private function buyWithWax(int $userId, DarkMatterPackage $package, int $now): RedirectResponse
    {
        $order = $this->orders->open($userId, $package, $this->shop->currency(), $now, DarkMatterShop::WAX);

        try {
            $units = $this->rates->units($package->price, $order->currency);
        } catch (WaxException $e) {
            report($e);
            $this->orders->fail($order);
            $this->message('dm_wax_rate_failed', DarkMatterShop::PAGE);
        }

        $minutes = config('premium.wax.quote_minutes');
        $memo = WaxPayments::memo($this->context->current()->slug ?? '', $order->id);
        $this->orders->quoteWax($order, $units, $memo, $now + (is_int($minutes) ? $minutes : 30) * 60);

        return redirect('game.php?page=darkmatter&order=' . $order->id);
    }

    /**
     * About what a price comes to in WAX; empty if the rate cannot be read.
     */
    private function waxPrice(int $cents): string
    {
        try {
            return WaxRates::format($this->rates->units($cents, $this->shop->currency()), $this->decimalMark());
        } catch (WaxException) {
            return '';
        }
    }

    private function decimalMark(): string
    {
        $mark = __('game/darkmatter.dm_decimal_mark');

        return is_string($mark) ? $mark : '.';
    }

    private function order(Request $request, int $userId): View
    {
        $order = $this->orders->findForUser($request->integer('order'), $userId);

        if ($order === null) {
            $this->message('dm_order_not_found', DarkMatterShop::PAGE);
        }

        $cancelled = $request->query('cancelled') !== null;
        $wax = $order->provider === DarkMatterShop::WAX;
        // a WAX quote that ran out: a transfer now is left to the admin
        $status = $wax && $order->status === DarkMatterOrder::PENDING && time() > ($order->expires_at ?? 0) ? DarkMatterOrder::EXPIRED : $order->status;
        $text = $wax && in_array($status, [DarkMatterOrder::PENDING, DarkMatterOrder::EXPIRED], true) ? 'dm_status_wax_' . $status : 'dm_status_' . $status;

        return view('premium.order', [
            'order_id' => $order->id,
            'dark_matter' => $this->format->prettyNumber($order->dark_matter),
            'price' => $this->shop->formatPrice($order->amount, $order->currency),
            'status' => $status,
            'status_text' => (string) __('game/darkmatter.' . $text, ['amount' => $this->format->prettyNumber($order->dark_matter)]),
            'cancelled' => $cancelled && $status === DarkMatterOrder::PENDING,
            // waiting for the webhook or the transfer: look again in a moment
            'refresh' => !$cancelled && $status === DarkMatterOrder::PENDING,
            'wax' => $wax && $status === DarkMatterOrder::PENDING ? [
                'account' => $this->shop->waxAccount(),
                'amount' => WaxRates::format($order->provider_amount ?? 0, '.'),
                'quantity' => number_format(($order->provider_amount ?? 0) / WaxChain::UNITS, 8, '.', '') . ' ' . WaxChain::SYMBOL,
                'memo' => $order->provider_session ?? '',
                'until' => $this->timing->formatExtendedDate($order->expires_at ?? 0),
            ] : null,
        ]);
    }

    /**
     * Shows the message and ends the request (Functions::message halts).
     */
    private function message(string $key, string $back): never
    {
        Functions::message((string) __('game/darkmatter.' . $key), $back, 3);

        throw new LogicException('Functions::message() returned');
    }
}
