<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Mail\VerifyEmail;
use App\Mail\Welcome;
use App\Models\User;
use App\Services\Instances\InstanceContext;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Players who registered on the start page confirm their email address
 * with a signed link. Until then the shop is closed to them, and an account
 * left unconfirmed is deleted after a week: throwaway addresses and second
 * accounts cost more. Admins, bots and players the admin created count as
 * confirmed; the admin switches it off with the option reg_verify_email.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class EmailVerification
{
    public const OPTION = 'reg_verify_email';

    public const DAYS = 7;

    public function __construct(
        private SettingsService $settings,
        private InstanceContext $context,
        private AccountEraser $eraser,
    ) {
    }

    public function required(): bool
    {
        return $this->settings->getBool(self::OPTION);
    }

    /**
     * Whether the player still has to confirm the address.
     */
    public function pending(User $user): bool
    {
        // admins count as confirmed (a model fresh from create has no rank yet)
        return $user->email_verified_at === null && !($user->authlevel > 0) && $this->required();
    }

    /**
     * When the unconfirmed account is deleted.
     */
    public function deadline(User $user): int
    {
        return (int) $user->register_time + self::DAYS * 86400;
    }

    /**
     * The first mail to a player who registered: the link that confirms the
     * address, or, without that requirement, the welcome email (the
     * address then counts as confirmed, so switching it on later deletes
     * nobody). A mail server that cannot be reached ends nothing: the
     * account exists already.
     */
    public function greet(User $user, bool $welcomeMail): void
    {
        if ($this->required()) {
            $this->send($user);

            return;
        }

        $this->confirm($user);

        if (!$welcomeMail) {
            return;
        }

        try {
            Mail::to($user->email)->send(new Welcome($user->name));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function confirm(User $user): void
    {
        $user->forceFill(['email_verified_at' => Carbon::now()])->save();
    }

    /**
     * Mails the link; false when the mail server could not be reached.
     */
    public function send(User $user): bool
    {
        try {
            Mail::to($user->email)->send(new VerifyEmail($user->name, $this->link($user), $this->deadline($user)));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }

    /**
     * Signed, for this address only (a changed address needs a new link),
     * with the universe in the signature. On the address of APP_URL, never
     * on the host the request named: a forged Host header would send the
     * player a link to someone else's server. The signature covers the
     * path (the route takes "signed:relative").
     */
    public function link(User $user): string
    {
        $parameters = ['id' => $user->id, 'hash' => self::hash($user->email)];
        $universe = $this->context->current()->slug ?? null;

        if ($universe !== null) {
            $parameters[InstanceContext::QUERY] = $universe;
        }

        $base = config('app.url');

        return rtrim(is_string($base) ? $base : '', '/')
            . URL::temporarySignedRoute('account.verify', Carbon::createFromTimestamp($this->deadline($user)), $parameters, false);
    }

    public static function hash(string $email): string
    {
        return hash('sha256', mb_strtolower($email));
    }

    /**
     * Deletes the accounts left unconfirmed past the deadline.
     *
     * @return int how many were deleted
     */
    public function pruneUnconfirmed(int $now): int
    {
        if (!$this->required()) {
            return 0;
        }

        $ids = DB::table('users')
            ->whereNull('email_verified_at')
            ->where('authlevel', 0)
            ->where('user_is_bot', 0)
            ->where('register_time', '<', $now - self::DAYS * 86400)
            ->pluck('id');

        $deleted = 0;

        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                continue;
            }

            try {
                $this->eraser->erase((int) $id);
                $deleted++;
            } catch (Throwable $e) {
                // the others still go; the operators see the one that did not
                report($e);
            }
        }

        return $deleted;
    }
}
