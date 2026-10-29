<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Operator;
use App\Services\Operators\OperatorLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only signed-in operators (password and code), and only for a few hours
 * after the sign-in, however busy.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class AuthenticateOperator
{
    /**
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $operator = Auth::guard(OperatorLogin::GUARD)->user();
        $signedIn = $request->session()->get(OperatorLogin::SIGNED_IN_AT);
        $hours = config('operators.max_hours');

        if (!$operator instanceof Operator || !$operator->hasTwoFactor() || !is_int($signedIn) ||
            now()->getTimestamp() - $signedIn > (is_int($hours) ? $hours : 8) * 3600) {
            Auth::guard(OperatorLogin::GUARD)->logout();
            $request->session()->invalidate();

            return redirect()->route('operator.login');
        }

        return $next($request);
    }
}
