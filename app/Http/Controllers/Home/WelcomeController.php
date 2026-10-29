<?php

declare(strict_types=1);

namespace App\Http\Controllers\Home;

use App\Services\Account\Invitations;
use App\Services\Account\RegistrationGate;
use App\Services\Instances\InstanceContext;
use App\Services\SettingsService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\ViewErrorBag;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class WelcomeController extends BaseController
{
    // the logo shown while the admin has not set one
    private const DEFAULT_LOGO = 'assets/images/xgp-logo-white.png';

    public function __construct(private SettingsService $settingsService, private RegistrationGate $gate)
    {
    }

    public function __invoke(Request $request): View | Factory
    {
        // typed before and sent back, or from an invitation link (…/?invite=CODE)
        $typed = old('invite');
        $invite = Invitations::normalize(is_string($typed) ? $typed : $request->string('invite')->toString());

        return view(
            'home.welcome',
            array_merge(
                [
                    'servername' => __('home/welcome.hm_title', ['game' => $this->settingsService->getString('game_name')]),
                    'gameLogo' => $this->settingsService->getString('game_logo') ?: asset(self::DEFAULT_LOGO),
                    'basePath' => url('/'),
                    // what was typed, after a refused registration
                    'userName' => self::old('username'),
                    'userEmail' => self::old('email'),
                    'forumUrl' => $this->settingsService->getString('forum_url'),
                    // with several universes: which one this is, and the way back to the lobby
                    'universe' => app(InstanceContext::class)->current()?->name,
                    'universe_name' => app(InstanceContext::class)->current()->name ?? __('home/welcome.hm_universe_name'),
                    'universe_ends' => app(InstanceContext::class)->current()?->endsAt,
                    'invite_only' => $this->gate->inviteOnly(),
                    'registration_full' => $this->gate->full(),
                    'inviteCode' => $invite,
                ],
                $this->getErrors($request)
            )
        );
    }

    /**
     * @return array{errors: list<array{divId: string, message: string}>, loginError: bool}
     */
    private function getErrors(Request $request): array
    {
        $errorsBlocks = [];
        $loginError = false;
        $errorsBags = $request->session()->get('errors');

        if ($errorsBags instanceof ViewErrorBag) {
            if ($errorsBags->hasBag('login')) {
                $loginError = true;

                foreach ($errorsBags->getBag('login')->getMessages() as $field => $errors) {
                    $errorsBlocks[] = [
                        'divId' => '#' . $field . 'Login',
                        'message' => $errors[0], // first error only
                    ];
                }
            }

            if ($errorsBags->hasBag('register')) {
                foreach ($errorsBags->getBag('register')->getMessages() as $field => $errors) {
                    $errorsBlocks[] = [
                        'divId' => '#' . $field,
                        'message' => $errors[0], // first error only
                    ];
                }
            }
        }

        return [
            'errors' => $errorsBlocks,
            'loginError' => $loginError,
        ];
    }

    private static function old(string $field): string
    {
        $value = old($field);

        return is_string($value) ? $value : '';
    }
}
