<?php

declare(strict_types=1);

namespace App\Services\Premium;

/**
 * A transfer as the chain recorded it, of WAX (eosio.token) or of the
 * game's own token; the amount in the token's smallest unit (1 WAX =
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
        public readonly string $symbol = WaxChain::SYMBOL,
    ) {
    }
}
