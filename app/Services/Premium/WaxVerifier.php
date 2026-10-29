<?php

declare(strict_types=1);

namespace App\Services\Premium;

/**
 * Asks a second, independent history server (premium.wax.verify_url) about
 * a transfer before it counts: a single server that lies, or is taken
 * over, must not be able to make up payments.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class WaxVerifier
{
    /**
     * @param list<TokenSpec> $tokens
     *
     * @return bool|null true: the same transfer, final there too; false:
     *                   that server knows the transaction differently;
     *                   null: not (yet) known or not final there
     *
     * @throws WaxException when that server cannot be reached
     */
    public function confirm(WaxTransfer $transfer, array $tokens): ?bool
    {
        $url = config('premium.wax.verify_url');

        // switched off on purpose (not recommended): the first server alone
        if (!is_string($url) || trim($url) === '') {
            return true;
        }

        $answer = WaxChain::getFrom(rtrim(trim($url), '/'), '/v2/history/get_transaction', ['id' => $transfer->trxId]);
        $actions = ($answer['executed'] ?? null) === true && is_array($answer['actions'] ?? null) ? $answer['actions'] : [];

        foreach ($actions as $action) {
            if (is_array($action) && ($action['global_sequence'] ?? null) === $transfer->sequence) {
                return $this->compare(WaxChain::transfer($action + ['trx_id' => $transfer->trxId], $tokens), $transfer, $answer['lib'] ?? null);
            }
        }

        return null;
    }

    private function compare(?WaxTransfer $seen, WaxTransfer $transfer, mixed $lib): ?bool
    {
        if ($seen === null || !self::same($seen, $transfer)) {
            return false;
        }

        return is_int($lib) && $seen->blockNum <= $lib ? true : null;
    }

    private static function same(WaxTransfer $seen, WaxTransfer $transfer): bool
    {
        return $seen->blockNum === $transfer->blockNum && $seen->from === $transfer->from && $seen->to === $transfer->to &&
            $seen->units === $transfer->units && $seen->symbol === $transfer->symbol && $seen->memo === $transfer->memo;
    }
}
