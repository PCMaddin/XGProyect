<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operator;

use App\Models\Operator;
use App\Services\Operators\OperatorLogin;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;

/**
 * /operator: the password, then the code of the authenticator app.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class LoginController extends BaseController
{
    public function __construct(private OperatorLogin $login)
    {
    }

    public function show(): View | RedirectResponse
    {
        if (Auth::guard(OperatorLogin::GUARD)->check()) {
            return redirect()->route('operator.universes');
        }

        return view('operator.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:254'], 'password' => ['required', 'string', 'max:200']]);
        $operator = $this->login->check($data['email'], $data['password'], (string) $request->ip());

        if ($operator === null) {
            // the same answer for an unknown e-mail, a wrong password and no two-factor sign-in
            return redirect()->route('operator.login')->withInput($request->only('email'))->withErrors(['email' => __('operator/operator.op_login_failed')]);
        }

        $this->login->passwordAccepted($request, $operator);

        return redirect()->route('operator.code');
    }

    public function showCode(Request $request): View | RedirectResponse
    {
        if ($this->login->pending($request) === null) {
            return redirect()->route('operator.login');
        }

        return view('operator.code');
    }

    public function verify(Request $request): RedirectResponse
    {
        $operator = $this->login->pending($request);
        $code = $request->input('code');

        if (!$operator instanceof Operator) {
            return redirect()->route('operator.login')->withErrors(['email' => __('operator/operator.op_code_expired')]);
        }

        if (!$this->login->verify($request, $operator, is_string($code) ? $code : '')) {
            // too many wrong codes: back to the password
            return $this->login->pending($request) === null
                ? redirect()->route('operator.login')->withErrors(['email' => __('operator/operator.op_code_expired')])
                : redirect()->route('operator.code')->withErrors(['code' => __('operator/operator.op_code_wrong')]);
        }

        return redirect()->route('operator.universes');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->login->logout($request);

        return redirect()->route('operator.login');
    }
}
