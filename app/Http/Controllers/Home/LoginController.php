<?php

declare(strict_types=1);

namespace App\Http\Controllers\Home;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\Auth\LoginFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;

class LoginController extends BaseController
{
    public function __construct(private LoginFlow $login)
    {
    }

    public function __invoke(LoginRequest $request): RedirectResponse
    {
        $credentials = [
            'email' => $request->validated('username'),
            'password' => $request->validated('password'),
        ];

        if (Auth::attempt($credentials)) {
            /** @var User $authUser */
            $authUser = Auth::getUser();

            return $this->login->passwordAccepted($request, $authUser, LoginFlow::GAME);
        }

        return back()->withErrors([
            'username' => __('home/welcome.hm_invalid_login'),
        ], 'login')->onlyInput('username');
    }
}
