<?php

declare(strict_types=1);

namespace App\Services\Premium;

/**
 * A WAX transfer as the chain recorded it: the system token only
 * (eosio.token, symbol WAX), amounts in its smallest unit (1 WAX =
 * 100 000 000).
 */
final class WaxTransfer
{
    public function __construct(
        public readonly int $sequence,
        public readonly string $trxId,
        public readonly int $blockNum,
        public readonly int $blockTime,
        public readonly string $from,
        public readonly string $to,
        public readonly int $units,
        public readonly string $memo,
    ) {
    }
}
