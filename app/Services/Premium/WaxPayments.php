<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Models\DarkMatterOrder;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use App\Services\Instances\SeasonPayouts;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Credits dark matter paid in WAX. The player transfers the quoted amount
 * to the shop's account with the memo of the order (xgp:<universe>:<order>,
 * or xgp:<order> on a server without universes). Every minute the
 * transfers to the account are read from the chain; each one that can no
 * longer be undone is settled once, in the universe its memo names:
 * - credited: an open WAX order, paid in time and in full;
 * - late, underpaid: the quote ran out, or too little came (the admin
 *   decides: credit by hand or send it back);
 * - not_pending: the order was paid already, or closed;
 * - unknown: no such universe or order (wrong memo);
 * - no_player: the player who ordered is gone;
 * - linked: a memo xgp-link:<code> links the sender to a player (WaxLinks);
 * - paid_out: a transfer from the shop's account with the memo
 *   xgp-prize:<id> pays a season prize in the game's token (SeasonPayouts).
 * Orders are paid in WAX or, with the payment method "token", in the
 * game's own token; each only in its own.
 * The transfers stay in the base database (wax_transfers) for the admin.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class WaxPayments
{
    public const CREDITED = 'credited';
    public const LATE = 'late';
    public const UNDERPAID = 'underpaid';
    public const NOT_PENDING = 'not_pending';
    public const UNKNOWN = 'unknown';
    public const NO_PLAYER = 'no_player';
    public const LINKED = 'linked';
    public const PAID_OUT = 'paid_out';
    public const UNCONFIRMED = 'unconfirmed';

    /** not a result: the transfer is looked at again next time */
    private const WAIT = 'wait';

    /** a transfer the second server still does not know after this long is not believed */
    private const CONFIRM_WAIT = 1800;

    /** pages of transfers read per check */
    private const MAX_PAGES = 10;

    /** the last transfer's time is read again with this overlap (seconds) */
    private const OVERLAP = 120;

    public function __construct(
        private WaxChain $chain,
        private DarkMatterShop $shop,
        private DarkMatterOrders $orders,
        private InstanceRegistry $registry,
        private InstanceContext $context,
        private WaxLinks $links,
        private SeasonPayouts $payouts,
        private WaxVerifier $verifier,
        private PurchaseReceipts $receipts,
    ) {
    }

    /**
     * The memo a player writes with the transfer.
     */
    public static function memo(string $universe, int $orderId): string
    {
        return 'xgp:' . ($universe === '' ? '' : $universe . ':') . $orderId;
    }

    /**
     * Reads the new transfers and settles them.
     *
     * @return array<string, int> how many transfers came to what
     *
     * @throws WaxException
     */
    public function check(int $now): array
    {
        $account = $this->shop->waxAccount();

        if ($account === '') {
            throw new WaxException('No WAX account is set (XGP_WAX_ACCOUNT).');
        }

        $lastIrreversible = $this->chain->lastIrreversibleBlock();
        $after = $this->resumeAt($now);
        $tokens = array_filter([TokenSpec::wax(), TokenSpec::game()]);
        $results = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $read = $this->chain->transfers($account, $after, $page * WaxChain::PAGE_SIZE, $tokens);

            foreach ($read['transfers'] as $transfer) {
                // later blocks can still be undone: next time
                if ($transfer->blockNum > $lastIrreversible) {
                    return $results;
                }

                $result = $this->known($transfer) ? null : $this->process($transfer, $account, $tokens, $now);

                // the second server has not seen it yet: next time
                if ($result === self::WAIT) {
                    return $results;
                }

                if ($result !== null) {
                    $results[$result] = ($results[$result] ?? 0) + 1;
                }
            }

            if ($read['actions'] < WaxChain::PAGE_SIZE) {
                break;
            }
        }

        return $results;
    }

    /**
     * The latest transfers for a universe (null: all) and those no universe
     * could be found for.
     *
     * @return Collection<int, \stdClass>
     */
    public function latest(?string $universe, int $limit): Collection
    {
        return $this->table()
            ->when($universe !== null, static fn (Builder $query) => $query->where(static fn (Builder $where) => $where->where('universe', $universe)->orWhereNull('universe')))
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get();
    }

    private function settle(WaxTransfer $transfer, int $now): string
    {
        $universe = null;
        $orderId = null;
        $result = self::UNKNOWN;

        if (WaxLinks::isLinkMemo($transfer->memo)) {
            $result = $this->links->link($transfer->memo, $transfer->from, $now) ? self::LINKED : self::UNKNOWN;
        } elseif (preg_match('/^xgp:(?:([a-z0-9][a-z0-9-]{0,31}):)?(\d{1,18})$/', trim($transfer->memo), $match) === 1) {
            $slug = $match[1];
            $orderId = (int) $match[2];
            $memo = self::memo($slug, $orderId);
            $work = fn (): string => $this->settleOrder($transfer, $orderId, $memo, $now);

            if ($this->registry->enabled()) {
                $instance = $slug === '' ? null : $this->registry->find($slug);
                $universe = $instance?->slug;
                $result = $instance === null ? self::UNKNOWN : $this->context->runIn($instance, $work);
            } elseif ($slug === '') {
                $result = $work();
            }
        }

        $this->record($transfer, $universe, $orderId, $result, $now);

        return $result;
    }

    /**
     * Settles a new transfer once the second server agrees; null for one
     * that is none of the shop's business.
     *
     * @param list<TokenSpec> $tokens
     *
     * @throws WaxException
     */
    private function process(WaxTransfer $transfer, string $account, array $tokens, int $now): ?string
    {
        $relevant = $transfer->to === $account || ($transfer->from === $account && SeasonPayouts::isPrizeMemo($transfer->memo));

        if (!$relevant) {
            return null;
        }

        $confirmed = self::changesState($transfer->memo) ? $this->verifier->confirm($transfer, $tokens) : true;

        return match (true) {
            $confirmed === null && $transfer->blockTime > $now - self::CONFIRM_WAIT => self::WAIT,
            $confirmed !== true => $this->unconfirmed($transfer, $now),
            $transfer->to === $account => $this->settle($transfer, $now),
            default => $this->settlePayout($transfer, $now),
        };
    }

    /**
     * A memo that pays an order, links an account or pays a prize: only
     * those need the second server's word.
     */
    private static function changesState(string $memo): bool
    {
        $memo = trim($memo);

        return WaxLinks::isLinkMemo($memo) || SeasonPayouts::isPrizeMemo($memo) || preg_match('/^xgp:(?:[a-z0-9][a-z0-9-]{0,31}:)?\d{1,18}$/', $memo) === 1;
    }

    /**
     * The second server contradicts the transfer, or never saw it: nothing
     * counts, the admin looks into it.
     */
    private function unconfirmed(WaxTransfer $transfer, int $now): string
    {
        Log::warning('WAX transfer not confirmed by the second history server', ['trx_id' => $transfer->trxId, 'sequence' => $transfer->sequence, 'memo' => $transfer->memo]);
        $this->record($transfer, null, null, self::UNCONFIRMED, $now);

        return self::UNCONFIRMED;
    }

    private function settlePayout(WaxTransfer $transfer, int $now): string
    {
        $game = TokenSpec::game();
        $result = $game !== null && $transfer->symbol === $game->symbol && $this->payouts->paid($transfer->memo, $transfer->to, $now) ? self::PAID_OUT : self::UNKNOWN;
        $this->record($transfer, null, null, $result, $now);

        return $result;
    }

    private function record(WaxTransfer $transfer, ?string $universe, ?int $orderId, string $result, int $now): void
    {
        $this->table()->insertOrIgnore([
            'sequence' => $transfer->sequence,
            'trx_id' => $transfer->trxId,
            'block_num' => $transfer->blockNum,
            'block_time' => $transfer->blockTime,
            'from_account' => $transfer->from,
            'units' => $transfer->units,
            'memo' => mb_substr($transfer->memo, 0, 256),
            'universe' => $universe,
            'order_id' => $orderId,
            'result' => $result,
            'checked_at' => $now,
            'symbol' => $transfer->symbol,
        ]);
    }

    private function settleOrder(WaxTransfer $transfer, int $orderId, string $memo, int $now): string
    {
        $order = DarkMatterOrder::find($orderId);

        // an order is paid in its own token: WAX, or the game's
        if ($order === null || !in_array($order->provider, [DarkMatterShop::WAX, DarkMatterShop::TOKEN], true) ||
            $order->provider_session !== $memo || $order->provider_currency !== $transfer->symbol) {
            return self::UNKNOWN;
        }

        return match (true) {
            $order->status !== DarkMatterOrder::PENDING => self::NOT_PENDING,
            $transfer->blockTime > ($order->expires_at ?? 0) => self::LATE,
            $transfer->units < ($order->provider_amount ?? PHP_INT_MAX) => self::UNDERPAID,
            default => $this->credit($order, $memo, $transfer, $now),
        };
    }

    private function credit(DarkMatterOrder $order, string $memo, WaxTransfer $transfer, int $now): string
    {
        try {
            if (!$this->orders->credit($order->id, $memo, $order->amount, $order->currency, $transfer->trxId, $now)) {
                return self::NOT_PENDING;
            }
        } catch (OrderNotCreditedException) {
            return self::NO_PLAYER;
        }

        $this->receipts->send($order->id);

        return self::CREDITED;
    }

    private function known(WaxTransfer $transfer): bool
    {
        return $this->table()->where('sequence', $transfer->sequence)->exists();
    }

    /**
     * Where the last check stopped, or a while back on the first one.
     */
    private function resumeAt(int $now): int
    {
        $last = $this->table()->max('block_time');
        $lookback = config('premium.wax.lookback_hours');

        return is_numeric($last) ? (int) $last - self::OVERLAP : $now - (is_int($lookback) ? $lookback : 24) * 3600;
    }

    private function table(): Builder
    {
        return DB::connection(InstanceRegistry::CONNECTION)->table('wax_transfers');
    }
}
