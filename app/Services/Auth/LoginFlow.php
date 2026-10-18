<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Game\HomePlanetService;
use App\Services\Security\PlayerTrail;
use App\Services\SessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * What follows a right password: with two-factor sign-in the account waits
 * signed out for the code (account.two-factor), otherwise it is signed in.
 * The game and the admin area finish the sign-in their own way.
 */
class LoginFlow
{
    public const GAME = 'game';
    public const ADMIN = 'admin';

    private const SESSION = 'two_factor_login';

    /** the code must follow the password within this many seconds */
    private const TIMEOUT = 300;

    public function __construct(
        private SessionService $sessions,
        private HomePlanetService $homePlanets,
        private PlayerTrail $trail,
    ) {
    }

    public function passwordAccepted(Request $request, User $user, string $target, string $redirect = ''): RedirectResponse
    {
        if (!$user->hasTwoFactor()) {
            return $this->complete($request, $user, $target, $redirect);
        }

        // signed out until the code is right
        Auth::logout();
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, ['user' => $user->id, 'target' => $target, 'redirect' => $redirect, 'at' => now()->getTimestamp()]);

        return redirect()->route('account.two-factor');
    }

    /**
     * The sign-in waiting for its code, if it has not run out.
     *
     * @return array{user: int, target: string, redirect: string}|null
     */
    public function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION);

        if (!is_array($pending) || !is_int($pending['user'] ?? null) || !is_int($pending['at'] ?? null) || now()->getTimestamp() - $pending['at'] > self::TIMEOUT) {
            $request->session()->forget(self::SESSION);

            return null;
        }

        return [
            'user' => $pending['user'],
            'target' => ($pending['target'] ?? null) === self::ADMIN ? self::ADMIN : self::GAME,
            'redirect' => is_string($pending['redirect'] ?? null) ? $pending['redirect'] : '',
        ];
    }

    public function complete(Request $request, User $user, string $target, string $redirect = ''): RedirectResponse
    {
        $request->session()->forget(self::SESSION);

        if (Auth::id() !== $user->id) {
            Auth::login($user);
        }

        $request->session()->regenerate();
        $this->sessions->setLoginData($user->id, $user->password);

        // for the cheat detection: where and on which device
        $device = $this->trail->device($request->cookie(PlayerTrail::DEVICE_COOKIE));
        $this->trail->login($user->id, (string) $request->ip(), (string) $request->userAgent(), $device, time());
        $response = $this->target($user, $target, $redirect);
        $response->headers->setCookie(cookie()->forever(PlayerTrail::DEVICE_COOKIE, $device));

        return $response;
    }

    private function target(User $user, string $target, string $redirect): RedirectResponse
    {
        if ($target === self::ADMIN) {
            return redirect('admin/' . ($redirect !== '' ? $redirect : 'home'));
        }

        $this->homePlanets->resetCurrentToHome($user);

        // a suspension that has run out is lifted
        $ban = $user->ban;

        if ($ban !== null && $ban->hasExpired()) {
            $ban->delete();
        }

        return redirect('game.php?page=overview');
    }
}
