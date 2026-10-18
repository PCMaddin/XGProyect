<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The frame of /operator, before the session starts: switched off or from
 * an address not allowed it does not exist (404); a session of its own
 * (files, its own cookie, short-lived), apart from any universe; pages
 * that no frame shows and no cache keeps.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class OperatorArea
{
    /**
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('operators.web') || !self::allowed((string) $request->ip())) {
            abort(404);
        }

        $idle = config('operators.idle_minutes');
        Config::set('session.driver', 'file');
        Config::set('session.cookie', 'xgp_operator');
        Config::set('session.lifetime', is_int($idle) ? $idle : 30);
        Config::set('session.expire_on_close', true);
        Config::set('session.same_site', 'strict');
        Config::set('session.http_only', true);
        Session::setDefaultDriver('file');

        $response = $next($request);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private static function allowed(string $ip): bool
    {
        $list = config('operators.allowed_ips');
        $allowed = array_values(array_filter(array_map('trim', explode(',', is_string($list) ? $list : ''))));

        return $allowed === [] || IpUtils::checkIp($ip, $allowed);
    }
}
