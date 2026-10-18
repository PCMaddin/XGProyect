<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operator;

use App\Models\Operator;
use App\Services\Auth\TwoFactor;
use App\Services\Operators\OperatorLog;
use App\Services\Operators\OperatorLogin;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * The operator's own account: a new password, new recovery codes. Both ask
 * for the current password and a code; a new app is set up on the command
 * line (xgp:operator:reset --two-factor).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class AccountController extends BaseController
{
    public function __construct(private TwoFactor $twoFactor, private OperatorLog $log)
    {
    }

    public function show(): View
    {
        $operator = self::operator();

        return view('operator.account', [
            'operator' => $operator,
            'codes_left' => $this->twoFactor->recoveryCodesLeft($operator),
            'new_codes' => session('operator_new_codes'),
        ]);
    }

    public function password(Request $request): RedirectResponse
    {
        $operator = self::operator();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
        ]);

        if (!$this->confirmed($operator, $data['current_password'], $data['code'])) {
            return redirect()->route('operator.account')->withErrors(['current_password' => __('operator/operator.op_confirm_failed')]);
        }

        $operator->forceFill(['password' => $data['password']])->save();
        $this->log->record($operator, $operator->email, OperatorLog::PASSWORD, (string) $request->ip());

        return redirect()->route('operator.account')->with('status', __('operator/operator.op_password_changed'));
    }

    public function codes(Request $request): RedirectResponse
    {
        $operator = self::operator();
        $data = $request->validate(['current_password' => ['required', 'string'], 'code' => ['required', 'string']]);

        if (!$this->confirmed($operator, $data['current_password'], $data['code'])) {
            return redirect()->route('operator.account')->withErrors(['current_password' => __('operator/operator.op_confirm_failed')]);
        }

        $codes = $this->twoFactor->regenerateRecoveryCodes($operator);
        $this->log->record($operator, $operator->email, OperatorLog::CODES, (string) $request->ip());

        // shown once
        return redirect()->route('operator.account')->with('operator_new_codes', $codes);
    }

    private function confirmed(Operator $operator, string $password, string $code): bool
    {
        return Hash::check($password, $operator->password) && $this->twoFactor->check($operator, $code, now()->getTimestamp());
    }

    private static function operator(): Operator
    {
        $operator = Auth::guard(OperatorLogin::GUARD)->user();

        if (!$operator instanceof Operator) {
            throw new LogicException('operator.auth lets only operators in');
        }

        return $operator;
    }
}
