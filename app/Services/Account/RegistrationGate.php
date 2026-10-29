<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * Who may register in this universe: up to a number of players
 * (reg_max_players, 0: no limit; bots do not count), and with
 * reg_invite_only only with an invitation code. A code taken for a
 * registration that then fails is given back.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class RegistrationGate
{
    private ?string $taken = null;

    public function __construct(private SettingsService $settings, private Invitations $invitations)
    {
    }

    public function inviteOnly(): bool
    {
        return $this->settings->getBool('reg_invite_only');
    }

    public function maxPlayers(): int
    {
        return max(0, $this->settings->getInt('reg_max_players'));
    }

    public function full(): bool
    {
        $max = $this->maxPlayers();

        return $max > 0 && DB::table('users')->where('user_is_bot', 0)->count() >= $max;
    }

    /**
     * Why the player may not register (a key of home/register), or null;
     * an invitation code is used up here.
     */
    public function admit(mixed $code, int $now): ?string
    {
        if ($this->full()) {
            return 're_universe_closed_full';
        }

        if (!$this->inviteOnly()) {
            return null;
        }

        if (!is_string($code) || !$this->invitations->redeem($code, $now)) {
            return 're_invite_invalid';
        }

        $this->taken = $code;

        return null;
    }

    /**
     * The registration failed after all: the code counts again.
     */
    public function release(): void
    {
        if ($this->taken !== null) {
            $this->invitations->giveBack($this->taken);
            $this->taken = null;
        }
    }
}
