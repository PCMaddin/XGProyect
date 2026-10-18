<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Models\DarkMatterOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The orders of the dark matter shop in the database of the current
 * universe. Crediting is idempotent: an order pays out once, only while it
 * is pending and only for the session, amount and currency it was opened
 * with, so a webhook sent again (or forged with a stolen secret but other
 * figures) credits nothing.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class DarkMatterOrders
{
    public function open(int $userId, DarkMatterPackage $package, string $currency, int $now, string $provider = DarkMatterShop::STRIPE): DarkMatterOrder
    {
        return DarkMatterOrder::create([
            'user_id' => $userId,
            'package' => $package->id,
            'dark_matter' => $package->darkMatter,
            'amount' => $package->price,
            'currency' => $currency,
            'provider' => $provider,
            'status' => DarkMatterOrder::PENDING,
            'created_at' => $now,
        ]);
    }

    /**
     * An order paid by transfer on the WAX chain (WAX or the game's token):
     * the amount to transfer, the memo that names the order (kept as its
     * session, unique) and until when the quote holds.
     */
    public function quoteTransfer(DarkMatterOrder $order, int $units, string $symbol, string $memo, int $expiresAt): void
    {
        $order->provider_amount = $units;
        $order->provider_currency = $symbol;
        $order->provider_session = $memo;
        $order->expires_at = $expiresAt;
        $order->save();
    }

    public function attachSession(DarkMatterOrder $order, string $sessionId): void
    {
        $order->provider_session = $sessionId;
        $order->save();
    }

    /**
     * The checkout could not be opened: nothing was charged.
     */
    public function fail(DarkMatterOrder $order): void
    {
        $order->status = DarkMatterOrder::FAILED;
        $order->save();
    }

    /**
     * Unfinished orders the player opened since then (a limit against
     * opening checkout sessions in a loop).
     */
    public function pendingSince(int $userId, int $since): int
    {
        return DarkMatterOrder::where('user_id', $userId)
            ->where('status', DarkMatterOrder::PENDING)
            ->where('created_at', '>=', $since)
            ->count();
    }

    public function findForUser(int $orderId, int $userId): ?DarkMatterOrder
    {
        return DarkMatterOrder::where('id', $orderId)->where('user_id', $userId)->first();
    }

    /**
     * The payment arrived: mark the order paid and credit its dark matter,
     * together or not at all.
     *
     * @return bool whether dark matter was credited now
     */
    public function credit(int $orderId, string $sessionId, int $amount, string $currency, string $paymentId, int $now): bool
    {
        return DB::transaction(function () use ($orderId, $sessionId, $amount, $currency, $paymentId, $now): bool {
            $order = DarkMatterOrder::whereKey($orderId)->lockForUpdate()->first();

            if ($order === null || $order->status !== DarkMatterOrder::PENDING || $sessionId === '' || $order->provider_session !== $sessionId || $order->amount !== $amount || $order->currency !== strtolower($currency)) {
                return false;
            }

            // only the first of two concurrent calls finds the order pending
            $marked = DarkMatterOrder::whereKey($orderId)
                ->where('status', DarkMatterOrder::PENDING)
                ->update(['status' => DarkMatterOrder::PAID, 'paid_at' => $now, 'provider_payment' => $paymentId === '' ? null : $paymentId]);

            if ($marked !== 1) {
                return false;
            }

            $credited = DB::table('premium')
                ->where('premium_user_id', $order->user_id)
                ->increment('premium_dark_matter', $order->dark_matter);

            if ($credited !== 1) {
                // the player is gone: leave the order pending for the admin
                throw new OrderNotCreditedException('No premium row for player ' . $order->user_id);
            }

            return true;
        });
    }

    /**
     * Stripe closed the session unpaid.
     */
    public function expire(int $orderId, string $sessionId): bool
    {
        return DarkMatterOrder::whereKey($orderId)
            ->where('status', DarkMatterOrder::PENDING)
            ->where('provider_session', $sessionId)
            ->update(['status' => DarkMatterOrder::EXPIRED]) === 1;
    }

    /**
     * The payment was refunded in full: mark the order and take back the
     * dark matter the player still has (never below zero).
     *
     * @return bool whether the order was refunded now
     */
    public function refund(string $paymentId, int $now): bool
    {
        if ($paymentId === '') {
            return false;
        }

        return DB::transaction(function () use ($paymentId, $now): bool {
            $order = DarkMatterOrder::where('provider_payment', $paymentId)->lockForUpdate()->first();

            if ($order === null || $order->status !== DarkMatterOrder::PAID) {
                return false;
            }

            DarkMatterOrder::whereKey($order->id)->update(['status' => DarkMatterOrder::REFUNDED, 'refunded_at' => $now]);

            $premium = DB::table('premium')->where('premium_user_id', $order->user_id)->lockForUpdate()->first(['premium_dark_matter']);

            if ($premium !== null) {
                $left = max(0, (int) $premium->premium_dark_matter - $order->dark_matter);
                DB::table('premium')->where('premium_user_id', $order->user_id)->update(['premium_dark_matter' => $left]);
            }

            return true;
        });
    }

    /**
     * The latest orders with the player's name (null for a deleted player).
     *
     * @return Collection<int, DarkMatterOrder>
     */
    public function latest(int $limit): Collection
    {
        return DarkMatterOrder::query()
            ->leftJoin('users', 'users.id', '=', 'dark_matter_orders.user_id')
            ->orderByDesc('dark_matter_orders.id')
            ->limit($limit)
            ->get(['dark_matter_orders.*', 'users.name as user_name']);
    }

    /**
     * Paid orders summed per currency, in cents.
     *
     * @return array<string, int>
     */
    public function revenue(): array
    {
        $revenue = [];

        // paid in the game's token: no money came in (see tokenRevenue)
        $rows = DarkMatterOrder::where('status', DarkMatterOrder::PAID)
            ->where('provider', '!=', DarkMatterShop::TOKEN)
            ->groupBy('currency')
            ->selectRaw('currency, SUM(amount) as total')
            ->get();

        foreach ($rows as $row) {
            $total = $row->getAttribute('total');
            $revenue[$row->currency] = is_numeric($total) ? (int) $total : 0;
        }

        return $revenue;
    }

    /**
     * The tokens the paid token orders brought in, in units by symbol.
     *
     * @return array<string, int>
     */
    public function tokenRevenue(): array
    {
        $revenue = [];

        $rows = DarkMatterOrder::where('status', DarkMatterOrder::PAID)
            ->where('provider', DarkMatterShop::TOKEN)
            ->groupBy('provider_currency')
            ->selectRaw('provider_currency, SUM(provider_amount) as total')
            ->get();

        foreach ($rows as $row) {
            $total = $row->getAttribute('total');
            $revenue[(string) $row->provider_currency] = is_numeric($total) ? (int) $total : 0;
        }

        return $revenue;
    }
}
