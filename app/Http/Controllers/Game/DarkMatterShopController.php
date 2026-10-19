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
use App\Services\Legal\LegalPages;
use App\Services\Premium\DarkMatterOrders;
use App\Services\Premium\DarkMatterPackage;
use App\Services\Premium\DarkMatterShop;
use App\Services\Premium\StripeCheckout;
use App\Services\Premium\StripeException;
use App\Services\Premium\TokenRates;
use App\Services\Premium\TokenSpec;
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
        private TokenRates $tokenRates,
        private InstanceContext $context,
        private FormatService $format,
        private TimingService $timing,
        private LegalPages $legal,
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
        $token = in_array(DarkMatterShop::TOKEN, $methods, true) ? TokenSpec::game() : null;
        $written = $this->legal->written();

        return view('premium.shop', [
            'token_symbol' => $token === null ? '' : $token->symbol,
            // money is at stake: point to two-factor sign-in
            'two_factor_hint' => $user instanceof User && !$user->hasTwoFactor(),
            'methods' => $methods,
            'packages' => $this->packages(in_array(DarkMatterShop::WAX, $methods, true), $token),
            'withdrawal_link' => in_array(LegalPages::WITHDRAWAL, $written, true),
            'terms_link' => in_array(LegalPages::TERMS, $written, true),
        ]);
    }

    /**
     * The packages with their prices in each way to pay.
     *
     * @return list<array<string, string>>
     */
    private function packages(bool $wax, ?TokenSpec $token): array
    {
        $market = $token === null ? null : $this->tokenRates->marketWax($token);

        return array_values(array_map(function (DarkMatterPackage $package) use ($wax, $token, $market): array {
            $units = $token === null ? 0 : $this->tokenRates->unitsFor($package->darkMatter, $token);

            return [
                'id' => $package->id,
                'dark_matter' => $this->format->prettyNumber($package->darkMatter),
                'price' => $this->shop->formatPrice($package->price, $this->shop->currency()),
                'wax' => $wax ? $this->waxPrice($package->price) : '',
                'token' => $token === null ? '' : $token->format($units, $this->decimalMark()),
                // what the tokens fetch on the market, for comparison
                'token_market' => $token === null || $market === null ? ''
                    : WaxRates::format((int) round($units / $token->scale() * $market * WaxChain::UNITS), $this->decimalMark()),
            ];
        }, $this->shop->packages()));
    }

    private function buy(Request $request, int $userId): RedirectResponse
    {
        $now = time();
        [$package, $method] = $this->orderRequest($request, $userId, $now);

        if ($method === DarkMatterShop::WAX) {
            return $this->buyWithWax($userId, $package, $now);
        }

        if ($method === DarkMatterShop::TOKEN) {
            return $this->buyWithToken($userId, $package, $now);
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
     * The package and the way to pay, or a message why nothing is bought.
     *
     * @return array{0: DarkMatterPackage, 1: string}
     */
    private function orderRequest(Request $request, int $userId, int $now): array
    {
        $id = $request->input('package');
        // only packages of the configuration: the price is never the player's
        $package = $this->shop->package(is_string($id) ? $id : '');
        $method = $request->input('method', DarkMatterShop::STRIPE);

        if ($package === null || $userId <= 0) {
            $this->message('dm_unknown_package', DarkMatterShop::PAGE);
        }

        if (!is_string($method) || !in_array($method, $this->shop->methods(), true)) {
            $this->message('dm_unknown_method', DarkMatterShop::PAGE);
        }

        // digital content: delivered at once only on the player's express
        // request, giving up the right of withdrawal (kept with the order)
        if (!$request->boolean('withdrawal_waiver')) {
            $this->message('dm_withdrawal_waiver_required', DarkMatterShop::PAGE);
        }

        if ($this->orders->pendingSince($userId, $now - self::OPEN_ORDERS_WINDOW) >= self::OPEN_ORDERS_LIMIT) {
            $this->message('dm_too_many_orders', DarkMatterShop::PAGE);
        }

        return [$package, $method];
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
        $this->orders->quoteTransfer($order, $units, WaxChain::SYMBOL, $memo, $now + (is_int($minutes) ? $minutes : 30) * 60);

        return redirect('game.php?page=darkmatter&order=' . $order->id);
    }

    /**
     * Opens an order paid in the game's token at the fixed rate.
     */
    private function buyWithToken(int $userId, DarkMatterPackage $package, int $now): RedirectResponse
    {
        $token = TokenSpec::game();

        if ($token === null) {
            $this->message('dm_unknown_method', DarkMatterShop::PAGE);
        }

        $order = $this->orders->open($userId, $package, $this->shop->currency(), $now, DarkMatterShop::TOKEN);
        $hours = config('premium.token.quote_hours');
        $memo = WaxPayments::memo($this->context->current()->slug ?? '', $order->id);
        $this->orders->quoteTransfer($order, $this->tokenRates->unitsFor($package->darkMatter, $token), $token->symbol, $memo, $now + (is_int($hours) ? $hours : 24) * 3600);

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
        $token = TokenSpec::forOrder($order->provider, (string) $order->provider_currency);
        $status = $order->shownStatus(time());
        $text = $token !== null && in_array($status, [DarkMatterOrder::PENDING, DarkMatterOrder::EXPIRED], true) ? 'dm_status_' . $order->provider . '_' . $status : 'dm_status_' . $status;

        return view('premium.order', [
            'order_id' => $order->id,
            'dark_matter' => $this->format->prettyNumber($order->dark_matter),
            // a token order costs tokens, never money
            'price' => $order->provider === DarkMatterShop::TOKEN && $token !== null
                ? $token->format($order->provider_amount ?? 0, $this->decimalMark())
                : $this->shop->formatPrice($order->amount, $order->currency),
            'status' => $status,
            'status_text' => (string) __('game/darkmatter.' . $text, ['amount' => $this->format->prettyNumber($order->dark_matter)]),
            'cancelled' => $cancelled && $status === DarkMatterOrder::PENDING,
            // waiting for the webhook or the transfer: look again in a moment
            'refresh' => !$cancelled && $status === DarkMatterOrder::PENDING,
            'wax' => $token !== null && $status === DarkMatterOrder::PENDING ? [
                'account' => $this->shop->waxAccount(),
                'amount' => $token->format($order->provider_amount ?? 0),
                'quantity' => $token->quantity($order->provider_amount ?? 0),
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
