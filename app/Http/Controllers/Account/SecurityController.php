<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Core\Enumerators\UserRanksEnumerator;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Hash;

/**
 * The account's two-factor sign-in: switch it on (scan the QR code, enter a
 * code, keep the recovery codes), get new recovery codes, switch it off
 * (password and code). The secret waits in the session until confirmed.
 */
class SecurityController extends BaseController
{
    private const PENDING_SECRET = 'two_factor_setup';

    private const ATTEMPTS = 5;

    public function __construct(
        private TwoFactor $twoFactor,
        private RateLimiter $limiter,
    ) {
    }

    public function show(Request $request): View
    {
        $user = $this->user($request);
        $secret = $request->session()->get(self::PENDING_SECRET);
        $setup = !$user->hasTwoFactor() && is_string($secret) ? $secret : null;
        $codes = $request->session()->get('recovery_codes');

        return view('account.security', [
            'enabled' => $user->hasTwoFactor(),
            'setup_secret' => $setup === null ? null : trim(chunk_split($setup, 4, ' ')),
            'setup_qr' => $setup === null ? null : $this->twoFactor->qrCode($user, $setup),
            'recovery_codes' => is_array($codes) ? $codes : [],
            'codes_left' => $this->twoFactor->recoveryCodesLeft($user),
            'admin' => $user->authlevel >= UserRanksEnumerator::GO,
            'required' => $request->session()->get('two_factor_required') === true,
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        if (!$this->user($request)->hasTwoFactor()) {
            $request->session()->put(self::PENDING_SECRET, $this->twoFactor->newSecret());
        }

        return redirect()->route('account.security');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $secret = $request->session()->get(self::PENDING_SECRET);
        $code = $request->input('code');

        if ($user->hasTwoFactor() || !is_string($secret) || !is_string($code) || $this->limited($user)) {
            return $this->failed($user, 'tf_wrong_code');
        }

        $codes = $this->twoFactor->enable($user, $secret, $code, time());

        if ($codes === null) {
            return $this->failed($user, 'tf_wrong_code');
        }

        $request->session()->forget(self::PENDING_SECRET);

        return redirect()->route('account.security')->with('recovery_codes', $codes)->with('success', __('account/two_factor.tf_enabled'));
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        // the admin area asks for it: an admin keeps it
        if ($user->authlevel >= UserRanksEnumerator::GO && config('auth.two_factor.admin_required') === true) {
            return redirect()->route('account.security')->with('error', __('account/two_factor.tf_required'));
        }

        if (!$this->confirmed($request, $user, true)) {
            return $this->failed($user, 'tf_wrong_password_or_code');
        }

        $this->twoFactor->disable($user);

        return redirect()->route('account.security')->with('success', __('account/two_factor.tf_disabled'));
    }

    public function codes(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        if (!$user->hasTwoFactor() || !$this->confirmed($request, $user, false)) {
            return $this->failed($user, 'tf_wrong_password');
        }

        return redirect()->route('account.security')
            ->with('recovery_codes', $this->twoFactor->regenerateRecoveryCodes($user))
            ->with('success', __('account/two_factor.tf_codes_renewed'));
    }

    /**
     * The password, and with $code also a code from the app or a recovery code.
     */
    private function confirmed(Request $request, User $user, bool $code): bool
    {
        $password = $request->input('password');
        $given = $request->input('code');

        if ($this->limited($user) || !is_string($password) || !Hash::check($password, $user->password)) {
            return false;
        }

        return !$code || (is_string($given) && $this->twoFactor->check($user, $given, time()));
    }

    private function limited(User $user): bool
    {
        return $this->limiter->tooManyAttempts('two-factor-settings:' . $user->id, self::ATTEMPTS);
    }

    private function failed(User $user, string $message): RedirectResponse
    {
        $this->limiter->hit('two-factor-settings:' . $user->id, 60);

        return redirect()->route('account.security')->with('error', __('account/two_factor.' . $message));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
