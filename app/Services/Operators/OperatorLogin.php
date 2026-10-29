<?php

declare(strict_types=1);

namespace App\Services\Operators;

use App\Models\Operator;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Signing in on /operator: the password, then always the code of the
 * authenticator app (or a recovery code). Two-factor sign-in is set up on
 * the server's command line when the account is made, never here: a
 * password alone cannot bring an app of its own. Between the two steps the
 * operator is not signed in; the step waits a few minutes and allows a few
 * wrong codes.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class OperatorLogin
{
    public const GUARD = 'operator';

    /** when the operator signed in (the area ends a while later in any case) */
    public const SIGNED_IN_AT = 'operator_signed_in_at';

    private const PENDING = 'operator_pending';

    /** the code must follow the password within this many seconds */
    private const TIMEOUT = 600;

    public function __construct(
        private TwoFactor $twoFactor,
        private OperatorLog $log,
    ) {
    }

    /**
     * The operator with this e-mail and password, or null. Takes as long
     * for an unknown e-mail as for a wrong password.
     */
    public function check(string $email, string $password, string $ip): ?Operator
    {
        $operator = Operator::where('email', mb_strtolower(trim($email)))->first();
        $hash = $operator->password ?? '$2y$12$' . str_repeat('x', 53);

        // no two-factor sign-in: no way in (set it up with xgp:operator:reset)
        if ($operator === null || !Hash::check($password, $hash) || !$operator->hasTwoFactor()) {
            $this->log->record(null, $email, OperatorLog::LOGIN_FAILED, $ip);

            return null;
        }

        return $operator;
    }

    /**
     * The password was right: the code comes next (or the setup).
     */
    public function passwordAccepted(Request $request, Operator $operator): void
    {
        Auth::guard(self::GUARD)->logout();
        $request->session()->regenerate();
        $request->session()->put(self::PENDING, [
            'operator' => $operator->id,
            'at' => now()->getTimestamp(),
            'tries' => 0,
        ]);
    }

    /**
     * The operator waiting for the code, if the step has not run out.
     */
    public function pending(Request $request): ?Operator
    {
        $pending = $this->pendingData($request);

        return $pending === null ? null : Operator::find($pending['operator']);
    }

    /**
     * Checks the code; on success the operator is signed in. Too many
     * wrong codes end the step: the password again.
     */
    public function verify(Request $request, Operator $operator, string $code): bool
    {
        $pending = $this->pendingData($request);

        if ($pending === null || $pending['operator'] !== $operator->id) {
            return false;
        }

        if (!$this->twoFactor->check($operator, $code, now()->getTimestamp())) {
            $this->failed($request, $operator, $pending);

            return false;
        }

        $this->complete($request, $operator);

        return true;
    }

    public function logout(Request $request): void
    {
        $operator = Auth::guard(self::GUARD)->user();

        if ($operator instanceof Operator) {
            $this->log->record($operator, $operator->email, OperatorLog::LOGOUT, (string) $request->ip());
        }

        Auth::guard(self::GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function complete(Request $request, Operator $operator): void
    {
        $request->session()->forget(self::PENDING);
        Auth::guard(self::GUARD)->login($operator);
        $request->session()->regenerate();
        $request->session()->put(self::SIGNED_IN_AT, now()->getTimestamp());

        $operator->forceFill(['last_login_at' => now()->getTimestamp(), 'last_login_ip' => (string) $request->ip()])->save();
        $this->log->record($operator, $operator->email, OperatorLog::LOGIN, (string) $request->ip());
    }

    /**
     * @param array{operator: int, at: int, tries: int} $pending
     */
    private function failed(Request $request, Operator $operator, array $pending): void
    {
        $this->log->record($operator, $operator->email, OperatorLog::CODE_FAILED, (string) $request->ip());
        $attempts = config('operators.attempts');
        $pending['tries']++;

        if ($pending['tries'] >= (is_int($attempts) ? $attempts : 5)) {
            $request->session()->forget(self::PENDING);

            return;
        }

        $request->session()->put(self::PENDING, $pending);
    }

    /**
     * @return array{operator: int, at: int, tries: int}|null
     */
    private function pendingData(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING);

        if (!is_array($pending) || !is_int($pending['operator'] ?? null) || !is_int($pending['at'] ?? null) ||
            now()->getTimestamp() - $pending['at'] > self::TIMEOUT) {
            $request->session()->forget(self::PENDING);

            return null;
        }

        return [
            'operator' => $pending['operator'],
            'at' => $pending['at'],
            'tries' => is_int($pending['tries'] ?? null) ? $pending['tries'] : 0,
        ];
    }
}
