<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\User;
use App\Services\Auth\LoginFlow;
use App\Services\Auth\TwoFactor;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * The second step of signing in: the code from the authenticator app, or
 * a recovery code. Five tries a minute per account.
 */
class TwoFactorChallengeController extends BaseController
{
    private const ATTEMPTS = 5;

    public function __construct(
        private LoginFlow $login,
        private TwoFactor $twoFactor,
        private RateLimiter $limiter,
    ) {
    }

    public function show(Request $request): View | RedirectResponse
    {
        $pending = $this->login->pending($request);

        if ($pending === null) {
            return redirect()->route('home.index');
        }

        return view('account.two_factor_challenge', ['admin' => $pending['target'] === LoginFlow::ADMIN]);
    }

    /**
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function verify(Request $request): RedirectResponse
    {
        $pending = $this->login->pending($request);
        $user = $pending === null ? null : User::find($pending['user']);

        if ($pending === null || !$user instanceof User) {
            return redirect()->route('home.index');
        }

        $key = 'two-factor:' . $user->id;

        if ($this->limiter->tooManyAttempts($key, self::ATTEMPTS)) {
            return back()->with('error', __('account/two_factor.tf_too_many', ['seconds' => $this->limiter->availableIn($key)]));
        }

        $code = $request->input('code');

        if (!is_string($code) || !$this->twoFactor->check($user, $code, time())) {
            $this->limiter->hit($key, 60);

            return back()->with('error', __('account/two_factor.tf_wrong_code'));
        }

        $this->limiter->clear($key);

        return $this->login->complete($request, $user, $pending['target'], $pending['redirect']);
    }
}
