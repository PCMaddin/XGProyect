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
     *
     * @param list<int> $bots
     *
     * @return list<array{0: int, 1: int, 2: int}> account, other account, sign-ins of both
     */
    private function sharedBy(string $column, int $since, array $bots): array
    {
        $groups = [];

        $rows = DB::table('login_events')
            ->where('created_at', '>=', $since)
            ->where($column, '!=', '')
            ->whereNotIn('user_id', $bots)
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

        $points = $this->points();
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
     * @param list<int> $bots
     */
    private function activity(int $now, array $bots): int
    {
        $sinceHour = intdiv($now, 3600) - 7 * 24;
        $hoursNeeded = self::setting('active_hours', 20);
        $daysNeeded = self::setting('active_days', 3);
        $pace = self::setting('requests_per_hour', 1200);
        $days = [];
        $busiest = [];

        $rows = DB::table('activity_hours')->where('hour', '>=', $sinceHour)->whereNotIn('user_id', $bots)->get(['user_id', 'hour', 'requests']);

        foreach ($rows as $row) {
            $values = get_object_vars($row);
            $user = self::asInt($values['user_id'] ?? 0);
            $day = intdiv(self::asInt($values['hour'] ?? 0), 24);
            $days[$user][$day] = ($days[$user][$day] ?? 0) + 1;
            $busiest[$user] = max($busiest[$user] ?? 0, self::asInt($values['requests'] ?? 0));
        }

        $found = 0;

        foreach ($days as $user => $hoursPerDay) {
            $fullDays = count(array_filter($hoursPerDay, static fn (int $hours): bool => $hours >= $hoursNeeded));

            if ($fullDays >= $daysNeeded) {
                $found += $this->suspicions->note($user, 'around_clock', 0, 50, ['days' => $fullDays], $now);
            }

            if ($busiest[$user] >= $pace) {
                $found += $this->suspicions->note($user, 'machine_pace', 0, 50, ['requests' => $busiest[$user]], $now);
            }
        }

        return $found;
    }

    /**
     * @return array<int, float> total points by player
     */
    private function points(): array
    {
        $points = [];

        foreach (DB::table('users_statistics')->pluck('user_statistic_total_points', 'user_statistic_user_id')->all() as $user => $total) {
            $points[self::asInt($user)] = self::asFloat($total);
        }

        return $points;
    }

    private static function setting(string $key, int $default): int
    {
        $value = config('cheats.' . $key);

        return is_int($value) ? $value : $default;
    }
}
