<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\User;
use App\Services\Account\AccountEraser;
use App\Services\Account\AccountExport;
use App\Services\Auth\TwoFactor;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The player's own data: download everything the universe keeps about the
 * account, or delete the account at once. Both ask for the password (and
 * the code, with two-factor sign-in), so an open session alone does
 * neither.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PrivacyController extends BaseController
{
    private const ATTEMPTS = 5;

    public function __construct(
        private AccountExport $export,
        private AccountEraser $eraser,
        private TwoFactor $twoFactor,
        private RateLimiter $limiter,
    ) {
    }

    public function show(Request $request): View
    {
        $user = $this->user($request);

        return view('account.privacy', [
            'two_factor' => $user->hasTwoFactor(),
            'admin' => $user->authlevel > 0,
        ]);
    }

    public function download(Request $request): StreamedResponse | RedirectResponse
    {
        $user = $this->user($request);

        if (!$this->confirmed($request, $user)) {
            return $this->failed($user, 'pv_wrong_password');
        }

        $data = $this->export->export($user->id, time());
        $name = 'xgproyect-' . preg_replace('/[^A-Za-z0-9_-]/', '', $user->name) . '-' . date('Y-m-d') . '.json';

        return response()->streamDownload(static function () use ($data): void {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }, $name, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store, private']);
    }

    public function destroy(Request $request): View | RedirectResponse
    {
        $user = $this->user($request);

        // an admin's account carries the bans they set: rights go first
        if ($user->authlevel > 0) {
            return redirect()->route('account.privacy')->with('error', __('account/privacy.pv_admin'));
        }

        if (!$request->boolean('confirm') || !$this->confirmed($request, $user)) {
            return $this->failed($user, 'pv_wrong_password');
        }

        $this->eraser->erase($user->id);
        Log::info('Account deleted by its player', ['user' => $user->id]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return view('account.deleted');
    }

    /**
     * The password, and with two-factor sign-in a code too.
     */
    private function confirmed(Request $request, User $user): bool
    {
        $password = $request->input('password');
        $code = $request->input('code');

        if ($this->limiter->tooManyAttempts($this->key($user), self::ATTEMPTS) || !is_string($password) || !Hash::check($password, $user->password)) {
            return false;
        }

        return !$user->hasTwoFactor() || (is_string($code) && $this->twoFactor->check($user, $code, time()));
    }

    private function failed(User $user, string $message): RedirectResponse
    {
        $this->limiter->hit($this->key($user), 900);

        return redirect()->route('account.privacy')->with('error', __('account/privacy.' . $message));
    }

    private function key(User $user): string
    {
        return 'account-privacy:' . $user->id;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
