<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Support\Facades\DB;

/**
 * Looks for signs of cheating in the universe's recent trail and lists
 * them as suspicions for the admin. Signs, not proof: a household shares
 * an address, friends send each other resources. The admin decides.
 *
 * - shared_device: accounts signed in from the same browser (strong sign)
 * - shared_ip:     accounts signed in from the same address
 * - pushing/pushed: one sends a stronger one far more resources than come
 *                  back (stronger still when they share a device or address)
 * - around_clock:  active nearly every hour of the day on several days
 * - machine_pace:  more requests in an hour than a person clicks
 *
 * Bots (the game's own AI players) are left out.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class CheatScanner
{
    use ReadsMixedValues;

    public function __construct(private Suspicions $suspicions)
    {
    }

    /**
     * @return int how many suspicions were found or renewed
     */
    public function scan(int $now): int
    {
        $since = $now - self::setting('window_days', 30) * 86400;
        $bots = array_values(array_map(static fn (mixed $id): int => self::asInt($id), DB::table('users')->where('user_is_bot', true)->pluck('id')->all()));

        $found = 0;
        $devices = $this->sharedBy('device', $since, $bots);
        $addresses = $this->sharedBy('ip', $since, $bots);

        foreach ($devices as [$user, $other, $logins]) {
            $found += $this->suspicions->note($user, 'shared_device', $other, 80, ['logins' => $logins], $now);
        }

        foreach ($addresses as [$user, $other, $logins]) {
            $found += $this->suspicions->note($user, 'shared_ip', $other, 40, ['logins' => $logins], $now);
        }

        $linked = [];

        foreach (array_merge($devices, $addresses) as [$user, $other]) {
            $linked[$user . ':' . $other] = true;
        }

        $found += $this->pushing($since, $bots, $linked, $now);

        return $found + $this->activity($now, $bots);
    }

    /**
     * Pairs of accounts that signed in with the same device or address.
     * Only values used by a few accounts count: behind the address of a
     * mobile network or a university thousands of players sign in, which
     * is no sign, and their pairs (millions) filled the memory.
     *
     * @param list<int> $bots
     *
     * @return list<array{0: int, 1: int, 2: int}> account, other account, sign-ins of both
     */
    private function sharedBy(string $column, int $since, array $bots): array
    {
        $groups = [];
        $events = fn () => DB::table('login_events')
            ->where('created_at', '>=', $since)
            ->where($column, '!=', '')
            ->whereNotIn('user_id', $bots);
        $shared = $events()
            ->select($column)
            ->groupBy($column)
            ->havingRaw('COUNT(DISTINCT user_id) BETWEEN 2 AND ?', [self::setting('shared_max_accounts', 20)]);

        $rows = $events()
            ->whereIn($column, $shared)
            ->groupBy($column, 'user_id')
            ->selectRaw($column . ' as shared, user_id, COUNT(*) as logins')
            ->get();

        foreach ($rows as $row) {
            $values = get_object_vars($row);
            $groups[self::asString($values['shared'] ?? '')][self::asInt($values['user_id'] ?? 0)] = self::asInt($values['logins'] ?? 0);
        }

        $pairs = [];

        foreach ($groups as $users) {
            foreach ($users as $user => $logins) {
                foreach ($users as $other => $otherLogins) {
                    if ($user !== $other) {
                        $pairs[] = [$user, $other, $logins + $otherLogins];
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * @param list<int>           $bots
     * @param array<string, bool> $linked pairs "user:other" sharing a device or address
     */
    private function pushing(int $since, array $bots, array $linked, int $now): int
    {
        $sent = [];

        $rows = DB::table('resource_transfers')
            ->where('created_at', '>=', $since)
            ->groupBy('from_user', 'to_user')
            ->selectRaw('from_user, to_user, SUM(metal + crystal + deuterium) as amount')
            ->get();

        foreach ($rows as $row) {
            $values = get_object_vars($row);
            $sent[self::asInt($values['from_user'] ?? 0)][self::asInt($values['to_user'] ?? 0)] = self::asFloat($values['amount'] ?? 0);
        }

        $points = $this->points(array_merge(array_keys($sent), ...array_map('array_keys', array_values($sent))));
        $threshold = self::setting('pushing_resources', 100000);
        $found = 0;

        foreach ($sent as $from => $receivers) {
            foreach ($receivers as $to => $amount) {
                $net = $amount - ($sent[$to][$from] ?? 0.0);

                if ($net < $threshold || ($points[$to] ?? 0.0) <= ($points[$from] ?? 0.0) || in_array($from, $bots, true) || in_array($to, $bots, true)) {
                    continue;
                }

                $score = isset($linked[$from . ':' . $to]) ? 90 : 60;
                $detail = ['resources' => (int) $net];
                $found += $this->suspicions->note($from, 'pushing', $to, $score, $detail, $now);
                $found += $this->suspicions->note($to, 'pushed', $from, $score, $detail, $now);
            }
        }

        return $found;
    }

    /**
     * Around the clock: active in $hoursNeeded hours of a day on $daysNeeded
     * days of the last week; at a script's pace: $pace requests in an hour.
     * Counted by the database: the hours of a week of all players (with
     * many players a hundred million rows) were read into PHP.
     *
     * @param list<int> $bots
     */
    private function activity(int $now, array $bots): int
    {
        $sinceHour = intdiv($now, 3600) - 7 * 24;
        $hours = fn () => DB::table('activity_hours')->where('hour', '>=', $sinceHour)->whereNotIn('user_id', $bots);
        $found = 0;

        $fullDays = DB::query()
            ->fromSub(
                $hours()
                    ->selectRaw('user_id, hour - hour % 24 AS day')
                    ->groupByRaw('user_id, hour - hour % 24')
                    ->havingRaw('COUNT(*) >= ?', [self::setting('active_hours', 20)]),
                'days'
            )
            ->selectRaw('user_id, COUNT(*) AS full_days')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= ?', [self::setting('active_days', 3)])
            ->orderBy('user_id')
            ->get();

        foreach ($fullDays as $row) {
            $values = get_object_vars($row);
            $found += $this->suspicions->note(self::asInt($values['user_id'] ?? 0), 'around_clock', 0, 50, ['days' => self::asInt($values['full_days'] ?? 0)], $now);
        }

        $fast = $hours()
            ->where('requests', '>=', self::setting('requests_per_hour', 1200))
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(requests) AS busiest')
            ->orderBy('user_id')
            ->get();

        foreach ($fast as $row) {
            $values = get_object_vars($row);
            $found += $this->suspicions->note(self::asInt($values['user_id'] ?? 0), 'machine_pace', 0, 50, ['requests' => self::asInt($values['busiest'] ?? 0)], $now);
        }

        return $found;
    }

    /**
     * @param list<int> $users
     *
     * @return array<int, float> total points of these players
     */
    private function points(array $users): array
    {
        $points = [];

        foreach (array_chunk(array_values(array_unique($users)), 1000) as $chunk) {
            $rows = DB::table('users_statistics')->whereIn('user_statistic_user_id', $chunk)->pluck('user_statistic_total_points', 'user_statistic_user_id');

            foreach ($rows->all() as $user => $total) {
                $points[self::asInt($user)] = self::asFloat($total);
            }
        }

        return $points;
    }

    private static function setting(string $key, int $default): int
    {
        $value = config('cheats.' . $key);

        return is_int($value) ? $value : $default;
    }
}
