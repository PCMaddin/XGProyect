<?php

declare(strict_types=1);

namespace App\Services\Instances;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Season prizes waiting for a WAX account, and redeeming them as dark
 * matter in another universe. Each prize is redeemed once.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class SeasonRewards
{
    /**
     * @return list<array{id: int, universe: string, category: string, rank: int, reward: int}>
     */
    public function unclaimed(string $waxAccount, string $exceptUniverse): array
    {
        $rewards = [];

        $rows = $this->table()
            ->where('wax_account', $waxAccount)
            ->whereNull('claimed_at')
            ->where('reward', '>', 0)
            ->where('universe', '!=', $exceptUniverse)
            ->orderBy('frozen_at')
            ->orderBy('id')
            ->get(['id', 'universe', 'category', 'rank', 'reward']);

        foreach ($rows as $row) {
            $values = get_object_vars($row);
            $rewards[] = [
                'id' => self::int($values['id'] ?? 0),
                'universe' => is_string($values['universe'] ?? null) ? $values['universe'] : '',
                'category' => is_string($values['category'] ?? null) ? $values['category'] : '',
                'rank' => self::int($values['rank'] ?? 0),
                'reward' => self::int($values['reward'] ?? 0),
            ];
        }

        return $rewards;
    }

    /**
     * Credits a prize of the WAX account to a player of the current
     * universe; returns the dark matter, 0 if it was not this account's or
     * was redeemed already.
     */
    public function claim(int $id, string $waxAccount, string $universe, int $userId, int $now): int
    {
        $reward = $this->table()->where('id', $id)->value('reward');
        $marked = $this->table()
            ->where('id', $id)
            ->where('wax_account', $waxAccount)
            ->whereNull('claimed_at')
            ->where('universe', '!=', $universe)
            ->update(['claimed_at' => $now, 'claimed_universe' => $universe]);

        if ($marked !== 1 || !is_numeric($reward)) {
            return 0;
        }

        try {
            $credited = DB::table('premium')->where('premium_user_id', $userId)->increment('premium_dark_matter', (int) $reward);
        } catch (Throwable $e) {
            $credited = 0;
            report($e);
        }

        if ($credited !== 1) {
            // nothing credited: the prize waits again
            $this->table()->where('id', $id)->update(['claimed_at' => null, 'claimed_universe' => null]);

            return 0;
        }

        return (int) $reward;
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
