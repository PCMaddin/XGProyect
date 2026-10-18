<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Models\DarkMatterOrder;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 * - linked: a memo xgp-link:<code> links the sender to a player (WaxLinks).
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
        $results = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $read = $this->chain->transfers($account, $after, $page * WaxChain::PAGE_SIZE);

            foreach ($read['transfers'] as $transfer) {
                // later blocks can still be undone: next time
                if ($transfer->blockNum > $lastIrreversible) {
                    return $results;
                }

                if ($transfer->to !== $account || $this->known($transfer)) {
                    continue;
                }

                $result = $this->settle($transfer, $now);
                $results[$result] = ($results[$result] ?? 0) + 1;
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
        ]);

        return $result;
    }

    private function settleOrder(WaxTransfer $transfer, int $orderId, string $memo, int $now): string
    {
        $order = DarkMatterOrder::find($orderId);

        if ($order === null || $order->provider !== DarkMatterShop::WAX || $order->provider_session !== $memo) {
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
            return $this->orders->credit($order->id, $memo, $order->amount, $order->currency, $transfer->trxId, $now) ? self::CREDITED : self::NOT_PENDING;
        } catch (OrderNotCreditedException) {
            return self::NO_PLAYER;
        }
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
