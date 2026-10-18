<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Services\Instances\InstanceRegistry;
use App\Services\Auth\Totp;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Links a player's account in a universe to a WAX account. The player
 * transfers any amount of WAX to the shop's account with the memo
 * "xgp-link:<code>"; xgp:wax:check then links the sender. The WAX account
 * is the identity across the universes that season prizes are paid to.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class WaxLinks
{
    public const MEMO_PREFIX = 'xgp-link:';

    /**
     * The code of the player's link, made on first use.
     */
    public function code(string $universe, int $userId, int $now): string
    {
        $code = $this->table()->where('universe', $universe)->where('user_id', $userId)->value('code');

        if (is_string($code)) {
            return $code;
        }

        $code = $this->newCode();
        $this->table()->insert(['universe' => $universe, 'user_id' => $userId, 'code' => $code, 'created_at' => $now]);

        return $code;
    }

    /**
     * A new code: the link waits for a transfer again (the old account stays
     * linked until the new transfer comes).
     */
    public function renew(string $universe, int $userId, int $now): string
    {
        $this->code($universe, $userId, $now);
        $code = $this->newCode();
        $this->table()->where('universe', $universe)->where('user_id', $userId)->update(['code' => $code]);

        return $code;
    }

    public function account(string $universe, int $userId): ?string
    {
        $account = $this->table()->where('universe', $universe)->where('user_id', $userId)->whereNotNull('linked_at')->value('wax_account');

        return is_string($account) ? $account : null;
    }

    /**
     * The linked WAX accounts of players of a universe, by player.
     *
     * @param list<int> $userIds
     *
     * @return array<int, string>
     */
    public function accounts(string $universe, array $userIds): array
    {
        $accounts = [];

        foreach ($this->table()->where('universe', $universe)->whereIn('user_id', $userIds)->whereNotNull('linked_at')->get(['user_id', 'wax_account']) as $row) {
            $values = get_object_vars($row);

            if (is_numeric($values['user_id'] ?? null) && is_string($values['wax_account'] ?? null)) {
                $accounts[(int) $values['user_id']] = $values['wax_account'];
            }
        }

        return $accounts;
    }

    /**
     * A transfer with a link memo: links its sender. False if no link has
     * that code. The code counts once: the memo is public on the chain
     * afterwards, and whoever sent it again would take over the link (and
     * the prizes paid to it).
     */
    public function link(string $memo, string $waxAccount, int $now): bool
    {
        $code = strtolower(substr(trim($memo), strlen(self::MEMO_PREFIX)));

        return $this->table()->where('code', $code)->update(['wax_account' => $waxAccount, 'linked_at' => $now, 'code' => $this->newCode()]) === 1;
    }

    public static function isLinkMemo(string $memo): bool
    {
        return preg_match('/^' . preg_quote(self::MEMO_PREFIX, '/') . '[a-z0-9]{12}$/i', trim($memo)) === 1;
    }

    private function newCode(): string
    {
        return substr(strtolower(Totp::base32(random_bytes(10))), 0, 12);
    }

    private function table(): Builder
    {
        return DB::connection(InstanceRegistry::CONNECTION)->table('wax_links');
    }
}
