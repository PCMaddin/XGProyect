<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Libraries\Users;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes an account of the current universe with everything that names
 * it: planets, messages, the alliance seat (Users), and the traces kept for
 * security (sign-ins with IP address and browser, sessions, activity, spy
 * log, suspicions). The admin, the delayed deletion and the player's own
 * deletion all go through here.
 *
 * Kept, without anything that leads back to a person: paid orders (the
 * law asks to keep them for years), the transfers between players (pruned
 * with the other traces) and the season results with the name removed.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class AccountEraser
{
    /** tables without a foreign key to the account, by the column naming it */
    private const BY_USER = [
        'login_events' => 'user_id',
        'sessions' => 'user_id',
        'activity_hours' => 'user_id',
        'espionage_log' => 'user_id',
        'wreck_fields' => 'user_id',
        'bans' => 'user_id',
    ];

    public function __construct(private Users $users, private InstanceContext $context)
    {
    }

    public function erase(int $userId): void
    {
        $email = DB::table('users')->where('id', $userId)->value('email');

        DB::transaction(function () use ($userId, $email): void {
            foreach (self::BY_USER as $table => $column) {
                DB::table($table)->where($column, $userId)->delete();
            }

            DB::table('suspicions')->where('user_id', $userId)->orWhere('other_user', $userId)->delete();

            // the attack groups the player started end with them
            $groups = DB::table('acs')->where('acs_owner', $userId)->pluck('acs_id')->all();
            DB::table('acs_members')->whereIn('acs_group_id', $groups)->orWhere('acs_user_id', $userId)->delete();
            DB::table('acs')->whereIn('acs_id', $groups)->delete();

            if (is_string($email)) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }

            $this->users->deleteUser($userId);
        });

        $this->eraseInBaseDatabase($userId);
    }

    /**
     * The WAX account linked in this universe, and the name in its hall of
     * fame (the places and paid prizes stay).
     */
    private function eraseInBaseDatabase(int $userId): void
    {
        $base = DB::connection(InstanceRegistry::CONNECTION);
        $universe = $this->context->current()->slug ?? '';

        if (Schema::connection(InstanceRegistry::CONNECTION)->hasTable('wax_links')) {
            $base->table('wax_links')->where('universe', $universe)->where('user_id', $userId)->delete();
        }

        if (Schema::connection(InstanceRegistry::CONNECTION)->hasTable('season_results')) {
            $base->table('season_results')->where('universe', $universe)->where('user_id', $userId)->update(['name' => '-']);
        }
    }
}
