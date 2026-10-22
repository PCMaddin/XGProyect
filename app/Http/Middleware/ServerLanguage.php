<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Libraries\Functions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page outside the installer speaks the server language. Before, only
 * the game pages did; the home page, registration, login, password reset,
 * the mails sent from there and the whole admin were always English.
 */
class ServerLanguage
{
    /**
     * @param Closure(Request): Response $next
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function handle(Request $request, Closure $next): Response
    {
        Functions::setLanguage();

        return $next($request);
    }
}
