<?php

declare(strict_types=1);

namespace App\Services\Instances;

use App\Services\Premium\TokenRates;
use App\Services\Premium\TokenSpec;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Season prizes paid out in the game's own token instead of dark matter.
 * The server never holds a key: it reserves the prizes (so they cannot be
 * redeemed as dark matter meanwhile) and lists the transfers for the
 * operator to sign in their wallet; xgp:wax:check sees each transfer with
 * the memo xgp-prize:<id> on the chain and marks its prize paid.
 */
class SeasonPayouts
{
    public const MEMO_PREFIX = 'xgp-prize:';

    /** claimed_universe while the transfer is on its way, and once paid */
    public const RESERVED = 'payout';
    public const PAID = 'token';

    public function __construct(private TokenRates $rates)
    {
    }

    /**
     * Reserves the universe's unredeemed prizes of linked WAX accounts and
     * returns the transfers to sign (again those reserved before).
     *
     * @return list<array{id: int, to: string, category: string, rank: int, reward: int, quantity: string, memo: string}>
     */
    public function reserve(string $universe, TokenSpec $token, int $now): array
    {
        $this->table()
            ->where('universe', $universe)
            ->whereNotNull('wax_account')
            ->where('reward', '>', 0)
            ->whereNull('claimed_at')
            ->update(['claimed_at' => $now, 'claimed_universe' => self::RESERVED]);

        $payouts = [];

        foreach ($this->table()->where('universe', $universe)->where('claimed_universe', self::RESERVED)->orderBy('category')->orderBy('rank')->get() as $row) {
            $values = get_object_vars($row);
            $id = self::int($values['id'] ?? 0);
            $reward = self::int($values['reward'] ?? 0);
            $payouts[] = [
                'id' => $id,
                'to' => is_string($values['wax_account'] ?? null) ? $values['wax_account'] : '',
                'category' => is_string($values['category'] ?? null) ? $values['category'] : '',
                'rank' => self::int($values['rank'] ?? 0),
                'reward' => $reward,
                'quantity' => $token->quantity($this->rates->unitsForPrize($reward, $token)),
                'memo' => self::MEMO_PREFIX . $id,
            ];
        }

        return $payouts;
    }

    /**
     * Gives the reserved prizes back: they can be redeemed as dark matter again.
     */
    public function release(string $universe): int
    {
        return $this->table()->where('universe', $universe)->where('claimed_universe', self::RESERVED)
            ->update(['claimed_at' => null, 'claimed_universe' => null]);
    }

    /**
     * A transfer from the shop's account with a prize memo: the prize is paid.
     */
    public function paid(string $memo, string $to, int $now): bool
    {
        $id = (int) substr(trim($memo), strlen(self::MEMO_PREFIX));

        return $this->table()->where('id', $id)->where('claimed_universe', self::RESERVED)->where('wax_account', $to)
            ->update(['claimed_at' => $now, 'claimed_universe' => self::PAID]) === 1;
    }

    public static function isPrizeMemo(string $memo): bool
    {
        return preg_match('/^' . preg_quote(self::MEMO_PREFIX, '/') . '\d{1,18}$/', trim($memo)) === 1;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function table(): Builder
    {
        return DB::connection(InstanceRegistry::CONNECTION)->table('season_results');
    }
}
