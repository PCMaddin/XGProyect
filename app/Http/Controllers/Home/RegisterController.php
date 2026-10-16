<?php

declare(strict_types=1);

namespace App\Http\Controllers\Home;

use App\Http\Requests\RegisterRequest;
use App\Mail\Welcome;
use App\Services\RegistrationService;
use App\Services\SessionService;
use App\Services\SettingsService;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Libraries\Functions;

class RegisterController extends BaseController
{
    public function __construct(
        private AuthManager $auth,
        private RegistrationService $registrationService,
        private SessionService $sessionService,
        private SettingsService $settingsService
    ) {
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

        $newUser = $this->registrationService->register(array_merge($request->validated(), [
            'name' => $request->validated()['username'],
            'lastip' => $request->ip(),
            'ip_at_reg' => $request->ip(),
            'agent' => $request->header('User-Agent'),
            'current_page' => $request->getRequestUri(),
        ]));

        if ($newUser === null) {
            return back()->withErrors([
                'username' => __('home/register.re_create_fail'),
            ], 'register');
        }

        // send welcome message
        if ($this->settingsService->getString('reg_welcome_message')) {
            /** @var string $welcomeContent */
            $welcomeContent = __('home/register.re_welcome_message_content');
            Functions::sendMessage(
                $newUser->id,
                0,
                0,
                5,
                __('home/register.re_welcome_message_from'),
                __('home/register.re_welcome_message_subject'),
                str_replace('%s', $newUser->name, $welcomeContent)
            );
        }

        // send welcome email
        if ($this->settingsService->getString('reg_welcome_email')) {
            Mail::to($newUser->email)->send(new Welcome(
                $newUser->name,
                (string) $request->string('password')
            ));
        }

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
