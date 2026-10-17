<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Libraries\Functions;
use App\Models\DarkMatterOrder;
use App\Models\User;
use App\Services\FormatService;
use App\Services\Instances\InstanceContext;
use App\Services\Premium\DarkMatterOrders;
use App\Services\Premium\DarkMatterPackage;
use App\Services\Premium\DarkMatterShop;
use App\Services\Premium\StripeCheckout;
use App\Services\Premium\StripeException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use LogicException;

/**
 * game.php?page=darkmatter: the dark matter shop of a Pay2Win universe.
 * Lists the packages; a POST of one opens an order and sends the player to
 * Stripe Checkout; Stripe sends them back to the order's page, which shows
 * whether the payment has arrived (the webhook credits it). Closed (Play2Win
 * or no Stripe keys): a message and back to the premium page.
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
        private InstanceContext $context,
        private FormatService $format,
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

        return view('premium.shop', [
            'packages' => array_values(array_map(fn (DarkMatterPackage $package): array => [
                'id' => $package->id,
                'dark_matter' => $this->format->prettyNumber($package->darkMatter),
                'price' => $this->shop->formatPrice($package->price, $this->shop->currency()),
            ], $this->shop->packages())),
        ]);
    }

    private function buy(Request $request, int $userId): RedirectResponse
    {
        $id = $request->input('package');
        // only packages of the configuration: the price is never the player's
        $package = $this->shop->package(is_string($id) ? $id : '');

        if ($package === null || $userId <= 0) {
            $this->message('dm_unknown_package', DarkMatterShop::PAGE);
        }

        $now = time();

        if ($this->orders->pendingSince($userId, $now - self::OPEN_ORDERS_WINDOW) >= self::OPEN_ORDERS_LIMIT) {
            $this->message('dm_too_many_orders', DarkMatterShop::PAGE);
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

    private function order(Request $request, int $userId): View
    {
        $order = $this->orders->findForUser($request->integer('order'), $userId);

        if ($order === null) {
            $this->message('dm_order_not_found', DarkMatterShop::PAGE);
        }

        $cancelled = $request->query('cancelled') !== null;

        return view('premium.order', [
            'order_id' => $order->id,
            'dark_matter' => $this->format->prettyNumber($order->dark_matter),
            'price' => $this->shop->formatPrice($order->amount, $order->currency),
            'status' => $order->status,
            'status_text' => (string) __('game/darkmatter.dm_status_' . $order->status, ['amount' => $this->format->prettyNumber($order->dark_matter)]),
            'cancelled' => $cancelled && $order->status === DarkMatterOrder::PENDING,
            // waiting for the webhook: look again in a moment
            'refresh' => !$cancelled && $order->status === DarkMatterOrder::PENDING,
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
