<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Facades\DB;

/**
 * Records what the cheat detection looks at, in the universe's database:
 * sign-ins (address, browser, device cookie), resources one player sends
 * another, and requests per hour.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PlayerTrail
{
    /** the browser's random id, kept for a long time */
    public const DEVICE_COOKIE = 'xgp_device';

    public function login(int $userId, string $ip, string $agent, string $device, int $now): void
    {
        DB::table('login_events')->insert([
            'user_id' => $userId,
            'ip' => mb_substr($ip, 0, 45),
            'agent' => mb_substr($agent, 0, 255),
            'device' => $device,
            'created_at' => $now,
        ]);
    }

    /**
     * The device id of the browser, or a new one.
     */
    public function device(mixed $cookie): string
    {
        return is_string($cookie) && preg_match('/^[a-f0-9]{32}$/', $cookie) === 1 ? $cookie : bin2hex(random_bytes(16));
    }

    public function transfer(int $from, int $to, float $metal, float $crystal, float $deuterium, int $now): void
    {
        if ($from === $to || $to <= 0 || $metal + $crystal + $deuterium <= 0) {
            return;
        }

        DB::table('resource_transfers')->insert([
            'from_user' => $from,
            'to_user' => $to,
            'metal' => $metal,
            'crystal' => $crystal,
            'deuterium' => $deuterium,
            'created_at' => $now,
        ]);
    }

    public function activity(int $userId, int $now): void
    {
        $hour = intdiv($now, 3600);
        $counted = DB::table('activity_hours')->where('user_id', $userId)->where('hour', $hour)->increment('requests');

        if ($counted === 0 && DB::table('activity_hours')->insertOrIgnore(['user_id' => $userId, 'hour' => $hour, 'requests' => 1]) === 0) {
            // another request of the hour was first
            DB::table('activity_hours')->where('user_id', $userId)->where('hour', $hour)->increment('requests');
        }
    }

    /**
     * Deletes sign-ins, transfers and activity older than the given time.
     */
    public function prune(int $before): void
    {
        DB::table('login_events')->where('created_at', '<', $before)->delete();
        DB::table('resource_transfers')->where('created_at', '<', $before)->delete();
        DB::table('activity_hours')->where('hour', '<', intdiv($before, 3600))->delete();
    }
}
