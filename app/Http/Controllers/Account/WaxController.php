<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\User;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\SeasonRewards;
use App\Services\Premium\DarkMatterShop;
use App\Services\Premium\WaxLinks;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * The player's WAX account and season prizes: link it with a transfer
 * (memo xgp-link:<code>), and redeem the prizes of past seasons as dark
 * matter in a Pay2Win universe.
 */
class WaxController extends BaseController
{
    public function __construct(
        private WaxLinks $links,
        private SeasonRewards $rewards,
        private DarkMatterShop $shop,
        private InstanceContext $context,
        private InstanceRegistry $registry,
    ) {
    }

    public function show(Request $request): View
    {
        $user = $this->user($request);
        $universe = $this->universe();
        $shopAccount = $this->shop->waxAccount();
        $linked = $this->links->account($universe, $user->id);

        return view('account.wax', [
            'shop_account' => $shopAccount,
            'memo' => $shopAccount === '' ? '' : WaxLinks::MEMO_PREFIX . $this->links->code($universe, $user->id, time()),
            'linked' => $linked,
            'rewards' => $linked === null ? [] : array_map(fn (array $reward): array => $reward + [
                'universe_name' => $this->registry->find($reward['universe'])->name ?? $reward['universe'],
            ], $this->rewards->unclaimed($linked, $universe)),
            'can_claim' => $this->shop->isPay2Win(),
        ]);
    }

    public function renew(Request $request): RedirectResponse
    {
        $this->links->renew($this->universe(), $this->user($request)->id, time());

        return redirect()->route('account.wax')->with('success', __('account/wax.wx_renewed'));
    }

    public function claim(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $linked = $this->links->account($this->universe(), $user->id);
        $amount = $linked !== null && $this->shop->isPay2Win()
            ? $this->rewards->claim($request->integer('reward'), $linked, $this->universe(), $user->id, time())
            : 0;

        return $amount > 0
            ? redirect()->route('account.wax')->with('success', __('account/wax.wx_claimed', ['amount' => number_format($amount, 0, ',', '.')]))
            : redirect()->route('account.wax')->with('error', __('account/wax.wx_claim_failed'));
    }

    private function universe(): string
    {
        return $this->context->current()->slug ?? '';
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
