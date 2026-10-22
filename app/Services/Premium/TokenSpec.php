<?php

declare(strict_types=1);

namespace App\Services\Premium;

/**
 * A token on the WAX chain: the contract that issues it, its symbol and
 * decimals. Only transfers of exactly this contract and symbol count; a
 * token of another contract with the same symbol is worth nothing.
 */
final class TokenSpec
{
    public function __construct(
        public readonly string $contract,
        public readonly string $symbol,
        public readonly int $decimals,
    ) {
    }

    /** the system token */
    public static function wax(): self
    {
        return new self(WaxChain::CONTRACT, WaxChain::SYMBOL, 8);
    }

    /**
     * The game's own token (config premium.token), or null if none is set.
     */
    public static function game(): ?self
    {
        $contract = config('premium.token.contract');
        $symbol = config('premium.token.symbol');
        $decimals = config('premium.token.decimals');

        if (!is_string($contract) || preg_match('/^[a-z1-5.]{1,12}$/', $contract) !== 1 ||
            !is_string($symbol) || preg_match('/^[A-Z]{1,7}$/', $symbol) !== 1 ||
            !is_int($decimals) || $decimals < 0 || $decimals > 8) {
            return null;
        }

        return new self($contract, $symbol, $decimals);
    }

    /**
     * The token an order is paid in: WAX, or the game's own (by the
     * order's symbol if the token is no longer configured).
     */
    public static function forOrder(string $provider, string $symbol): ?self
    {
        return match ($provider) {
            DarkMatterShop::WAX => self::wax(),
            DarkMatterShop::TOKEN => self::game() ?? new self('', $symbol, 4),
            default => null,
        };
    }

    public function scale(): int
    {
        return 10 ** $this->decimals;
    }

    /**
     * "12.5000 XGP" in units, or null if it is not this token with its decimals.
     */
    public function units(string $quantity): ?int
    {
        $pattern = $this->decimals === 0
            ? '/^(\d{1,15}) ' . preg_quote($this->symbol, '/') . '$/'
            : '/^(\d{1,15})\.(\d{' . $this->decimals . '}) ' . preg_quote($this->symbol, '/') . '$/';

        if (preg_match($pattern, $quantity, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * $this->scale() + (int) ($match[2] ?? 0);
    }

    /**
     * The exact quantity a transfer carries: "12.5000 XGP".
     */
    public function quantity(int $units): string
    {
        return number_format($units / $this->scale(), $this->decimals, '.', '') . ' ' . $this->symbol;
    }

    /**
     * For people: two decimals at most, the language's decimal mark.
     */
    public function format(int $units, string $mark = '.'): string
    {
        return number_format($units / $this->scale(), min(2, $this->decimals), $mark, '') . ' ' . $this->symbol;
    }
}
