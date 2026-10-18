<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Requests\RecoverRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Mail\Recover;
use App\Models\User;
use App\Services\Instances\InstanceContext;
use App\Services\SettingsService;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpFoundation\Response;

/**
 * Password recovery: a link with a one-time token goes to the account's
 * email address, and the password changes only when the player sets a new
 * one through it.
 *
 * Bugfix: the legacy flow replaced the password right away with a random
 * one mailed in plain text, so anybody who knew a player's email address
 * could lock them out; and its answer told whether an account existed for
 * an address.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class RecoverController extends BaseController
{
    public function __construct(private SettingsService $settingsService, private InstanceContext $context)
    {
    }

    public function __invoke(): View | Factory
    {
        return view('account.recover', ['gameName' => $this->settingsService->getString('game_name')]);
    }

    /**
     * Send the reset link. The answer is the same whether an account exists
     * for the address or not (and when a link was sent less than a minute ago).
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function recover(RecoverRequest $request): Response
    {
        Password::broker()->sendResetLink(
            ['email' => $request->validated()['email']],
            function (CanResetPassword $user, string $token): void {
                $email = $user->getEmailForPasswordReset();

                // the answer stays the same either way (it must not tell
                // whether an account exists); the failure is logged
                try {
                    Mail::to($email)->send(new Recover($this->context->link($this->resetLink($token, $email))));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        );

        return back()->with('message', __('account/recover.re_sent'));
    }

    public function showReset(string $token, RecoverRequest $request): View | Factory
    {
        return view('account.reset', [
            'gameName' => $this->settingsService->getString('game_name'),
            'token' => $token,
            'email' => $request->validated()['email'],
        ]);
    }

    /**
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function reset(ResetPasswordRequest $request): Response
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->password = $password; // hashed by the model's mutator
                $user->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->with('message', __('account/recover.re_reset_invalid'));
        }

        return redirect()->route('account.recover')->with('message', __('account/recover.re_reset_done'));
    }

    /**
     * The link in the mail, on the address of APP_URL, never on the host
     * the request named: a forged Host header would send the token to
     * someone else's server.
     */
    private function resetLink(string $token, string $email): string
    {
        $base = config('app.url');

        return rtrim(is_string($base) ? $base : '', '/') . route('account.reset', ['token' => $token, 'email' => $email], false);
    }
}
