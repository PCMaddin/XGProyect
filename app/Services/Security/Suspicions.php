<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Facades\DB;

/**
 * The suspicions of a universe and the admin's decision on them: open,
 * cleared (nothing wrong) or excluded (left out of the season's rankings
 * and prizes).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Suspicions
{
    public const OPEN = 'open';
    public const CLEARED = 'cleared';
    public const EXCLUDED = 'excluded';

    /**
     * Records a sign, or renews the one found before (keeping the admin's
     * decision on it).
     *
     * @param array<string, int|string> $detail
     *
     * @return int 1
     */
    public function note(int $user, string $kind, int $other, int $score, array $detail, int $now): int
    {
        $values = ['score' => $score, 'detail' => (string) json_encode($detail), 'updated_at' => $now];
        $updated = DB::table('suspicions')->where('user_id', $user)->where('kind', $kind)->where('other_user', $other)->update($values);

        if ($updated === 0) {
            DB::table('suspicions')->insertOrIgnore($values + ['user_id' => $user, 'kind' => $kind, 'other_user' => $other, 'status' => self::OPEN, 'created_at' => $now]);
        }

        return 1;
    }

    /**
     * The suspicions with the players' names, open ones and high scores first.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = DB::table('suspicions as s')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('users as o', 'o.id', '=', 's.other_user')
            ->leftJoin('users_statistics as st', 'st.user_statistic_user_id', '=', 's.user_id')
            ->get(['s.*', 'u.name as user_name', 'o.name as other_name', 'st.user_statistic_total_rank as user_rank']);
        $order = [self::OPEN => 0, self::EXCLUDED => 1, self::CLEARED => 2];
        $list = array_values(array_map(static fn (object $row): array => get_object_vars($row), $rows->all()));

        // open first, then excluded, then cleared; strong and recent signs first
        usort($list, static fn (array $first, array $second): int => [$order[$first['status']] ?? 3, $second['score'], $second['updated_at']] <=> [$order[$second['status']] ?? 3, $first['score'], $first['updated_at']]);

        return $list;
    }

    public function decide(int $id, string $status, string $note, int $now): bool
    {
        if (!in_array($status, [self::OPEN, self::CLEARED, self::EXCLUDED], true)) {
            return false;
        }

        return DB::table('suspicions')->where('id', $id)->update(['status' => $status, 'note' => $note, 'reviewed_at' => $now]) === 1;
    }

    /**
     * Players the admin has excluded from the season's rankings.
     *
     * @return list<int>
     */
    public function excluded(): array
    {
        $users = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, DB::table('suspicions')->where('status', self::EXCLUDED)->pluck('user_id')->all());

        return array_values(array_unique($users));
    }
}
