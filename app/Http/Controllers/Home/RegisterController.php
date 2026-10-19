<?php

declare(strict_types=1);

namespace App\Http\Controllers\Home;

use App\Http\Requests\RegisterRequest;
use App\Services\Account\EmailVerification;
use App\Services\Account\RegistrationGate;
use App\Services\RegistrationService;
use App\Exceptions\UniverseFull;
use App\Services\SessionService;
use App\Services\SettingsService;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use App\Libraries\Functions;

class RegisterController extends BaseController
{
    public function __construct(
        private AuthManager $auth,
        private RegistrationService $registrationService,
        private SessionService $sessionService,
        private SettingsService $settingsService,
        private EmailVerification $verification,
        private RegistrationGate $gate,
    ) {
    }

    /**
     * The welcome message points to the server's forum only when the admin
     * set one; the game has no tutorial, chat or support pages of its own.
     */
    private function welcomeMessage(string $playerName): string
    {
        $paragraphs = [str_replace('%s', e($playerName), (string) __('home/register.re_welcome_message_content'))];
        $forumUrl = $this->settingsService->getString('forum_url');

        if ($forumUrl !== '') {
            $paragraphs[] = (string) __('home/register.re_welcome_message_forum', ['url' => e($forumUrl)]);
        }

        $paragraphs[] = (string) __('home/register.re_welcome_message_closing');

        return implode('<br><br>', $paragraphs);
    }

    /**
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function __invoke(RegisterRequest $request): RedirectResponse
    {
        if ($this->settingsService->getBool('reg_enable') === false) {
            return back()->withErrors([
                'username' => __('home/register.re_disabled'),
            ], 'register')->onlyInput('username');
        }

        $refusal = $this->gate->admit($request->input('invite'), time());

        if ($refusal !== null) {
            return back()->withErrors([
                $refusal === 're_invite_invalid' ? 'invite' : 'username' => __('home/register.' . $refusal),
            ], 'register')->withInput($request->only('username', 'email', 'invite'));
        }

        try {
            $newUser = $this->registrationService->register(array_merge(Arr::except($request->validated(), ['invite']), [
                'name' => $request->validated()['username'],
                'lastip' => $request->ip(),
                'ip_at_reg' => $request->ip(),
                'agent' => $request->header('User-Agent'),
                'current_page' => $request->getRequestUri(),
            ]));
        } catch (UniverseFull) {
            $this->gate->release();

            return back()->withErrors([
                'username' => __('home/register.re_universe_full'),
            ], 'register')->onlyInput('username');
        }

        if ($newUser === null) {
            $this->gate->release();

            return back()->withErrors([
                'username' => __('home/register.re_create_fail'),
            ], 'register');
        }

        // send welcome message
        if ($this->settingsService->getString('reg_welcome_message')) {
            Functions::sendMessage(
                $newUser->id,
                0,
                0,
                5,
                __('home/register.re_welcome_message_from'),
                __('home/register.re_welcome_message_subject'),
                $this->welcomeMessage($newUser->name)
            );
        }

        // the link that confirms the address, or the welcome email
        $this->verification->greet($newUser, $this->settingsService->getBool('reg_welcome_email'));

        if (Auth::attempt($request->only('email', 'password'))) {
            $request->session()->regenerate();

            $authUser = $this->auth->getUser();
            if ($authUser !== null) {
                $this->sessionService->setLoginData(
                    $this->auth->id(),
                    $authUser->getAuthPassword()
                );
            }

            return redirect('game.php?page=overview');
        }

        return back()->withErrors([
            'username' => __('home/register.re_create_fail'),
        ], 'register');
    }
}
