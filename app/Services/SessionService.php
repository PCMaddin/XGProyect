<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Hash;
use UnexpectedValueException;

/**
 * The player's sign-in in the session: the id and a fingerprint of the
 * stored password hash, so a new password ends the other sessions.
 *
 * The fingerprint was a bcrypt hash, checked on every page view: some 70 ms
 * of processor time each, by far the largest cost of a page with many
 * players. Now it is an HMAC with the server's secret word, compared in
 * microseconds; a session that still holds the bcrypt hash is checked that
 * way once and then gets the fingerprint.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class SessionService
{
    private const PREFIX = 'hmac:';

    public function setLoginData(int | string | null $userId, string $password): void
    {
        session([
            'user_id' => $userId,
            'user_password' => $this->fingerprint($password),
        ]);
    }

    /**
     * Whether the session belongs to the password hash stored now.
     */
    public function matches(string $password, string $stored): bool
    {
        if (str_starts_with($stored, self::PREFIX)) {
            return hash_equals($this->fingerprint($password), $stored);
        }

        // signed in before the fingerprint: once the slow way
        if ($stored === '' || !Hash::check($password . '-' . $this->secretWord(), $stored)) {
            return false;
        }

        session(['user_password' => $this->fingerprint($password)]);

        return true;
    }

    private function fingerprint(string $password): string
    {
        return self::PREFIX . hash_hmac('sha256', $password, $this->secretWord());
    }

    private function secretWord(): string
    {
        $secretWord = config('SECRETWORD');

        if (!is_string($secretWord)) {
            throw new UnexpectedValueException('SECRETWORD must be a string.');
        }

        return $secretWord;
    }
}
