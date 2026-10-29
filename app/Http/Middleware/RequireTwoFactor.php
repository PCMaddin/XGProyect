<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin area only with two-factor sign-in switched on (unless
 * XGP_ADMIN_TWO_FACTOR=false): an admin without it is sent to set it up.
 */
class RequireTwoFactor
{
    /**
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('auth.two_factor.admin_required') === true && $user instanceof User && !$user->hasTwoFactor()) {
            return redirect()->route('account.security')->with('two_factor_required', true);
        }

        return $next($request);
    }
}
