<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the universe keeps about an account, as the player can ask for
 * it (right of access, data portability): the account, its planets and
 * what is on them, research, messages, friends, notes, purchases, items,
 * and the traces kept for security (sign-ins with IP address, sessions,
 * activity). Passwords, two-factor secrets and recovery codes stay out, as
 * do the findings of the cheat detection.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class AccountExport
{
    /** never part of an export */
    private const SECRET = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step', 'payload'];

    /** tables naming the account in one column */
    private const BY_USER = [
        'preferences' => 'preference_user_id',
        'premium' => 'premium_user_id',
        'research' => 'research_user_id',
        'users_statistics' => 'user_statistic_user_id',
        'notes' => 'note_owner',
        'fleets' => 'fleet_owner',
        'research_queues' => 'user_id',
        'dark_matter_orders' => 'user_id',
        'user_items' => 'user_id',
        'auction_bids' => 'user_id',
        'import_exports' => 'user_id',
        'login_events' => 'user_id',
        'sessions' => 'user_id',
        'activity_hours' => 'user_id',
        'espionage_log' => 'user_id',
        'bans' => 'user_id',
    ];

    /** tables naming the account as one side or the other */
    private const BETWEEN = [
        'messages' => ['message_sender', 'message_receiver'],
        'buddys' => ['buddy_sender', 'buddy_receiver'],
        'resource_transfers' => ['from_user', 'to_user'],
    ];

    /** what is on the planets, by the column naming the planet */
    private const ON_PLANETS = [
        'buildings' => 'building_planet_id',
        'ships' => 'ship_planet_id',
        'defenses' => 'defense_planet_id',
        'building_queues' => 'planet_id',
        'planet_boosters' => 'planet_id',
        'wreck_fields' => 'planet_id',
    ];

    public function __construct(private InstanceContext $context)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(int $userId, int $now): array
    {
        $account = self::rows(DB::table('users')->where('id', $userId)->get())[0] ?? [];
        $account['two_factor_enabled'] = ($account['two_factor_confirmed_at'] ?? null) !== null;
        $planets = self::rows(DB::table('planets')->where('planet_user_id', $userId)->get());
        $planetIds = array_column($planets, 'planet_id');

        $data = ['account' => $account, 'planets' => $planets];

        foreach (self::ON_PLANETS as $table => $column) {
            $data[$table] = self::rows(DB::table($table)->whereIn($column, $planetIds)->get());
        }

        foreach (self::BY_USER as $table => $column) {
            $data[$table] = self::rows(DB::table($table)->where($column, $userId)->get());
        }

        foreach (self::BETWEEN as $table => [$one, $other]) {
            $data[$table] = self::rows(DB::table($table)->where($one, $userId)->orWhere($other, $userId)->get());
        }

        $alliance = $account['ally_id'] ?? null;
        $data['alliance'] = self::rows(DB::table('alliance')->where('alliance_id', is_numeric($alliance) ? (int) $alliance : 0)
            ->get(['alliance_id', 'alliance_name', 'alliance_tag', 'alliance_owner', 'alliance_register_time']));

        return [
            'universe' => $this->context->current()->slug ?? '',
            'created_at' => gmdate('c', $now),
            'data' => $data + $this->baseDatabase($userId),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function baseDatabase(int $userId): array
    {
        $universe = $this->context->current()->slug ?? '';
        $data = [];

        foreach (['wax_links' => ['code'], 'season_results' => []] as $table => $hidden) {
            if (Schema::connection(InstanceRegistry::CONNECTION)->hasTable($table)) {
                $rows = DB::connection(InstanceRegistry::CONNECTION)->table($table)->where('universe', $universe)->where('user_id', $userId)->get();
                $data[$table] = array_map(static fn (array $row): array => array_diff_key($row, array_flip($hidden)), self::rows($rows));
            }
        }

        return $data;
    }

    /**
     * @param iterable<mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(iterable $rows): array
    {
        $list = [];

        foreach ($rows as $row) {
            $list[] = array_diff_key((array) $row, array_flip(self::SECRET));
        }

        return $list;
    }
}
