<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\LoginRequest;
use App\Models\User;
use App\Services\Auth\LoginFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use App\Core\Enumerators\UserRanksEnumerator;

class LoginController extends BaseController
{
    public function __construct(private readonly LoginFlow $login)
    {
    }

    public function __invoke(LoginRequest $request): Redirector | RedirectResponse
    {
        $redirect = $request->string('redirect')->toString();

        $credentials = [
            'email' => $request->validated('inputEmail'),
            'password' => $request->validated('inputPassword'),
        ];

        if (
            Auth::attempt(array_merge($credentials, ['authlevel' => UserRanksEnumerator::GO])) ||
            Auth::attempt(array_merge($credentials, ['authlevel' => UserRanksEnumerator::SGO])) ||
            Auth::attempt(array_merge($credentials, ['authlevel' => UserRanksEnumerator::ADMIN]))
        ) {
            /** @var User $authUser */
            $authUser = Auth::getUser();

            return $this->login->passwordAccepted($request, $authUser, LoginFlow::ADMIN, $redirect);
        }

        return back()->with('danger', __('admin/login.lg_error_wrong_data'));
    }
}
