<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language of /operator: ?lang=…, the one chosen before, or the browser's.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class OperatorLocale
{
    public const SESSION = 'operator_locale';

    /**
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $available = self::languages();
        $chosen = $request->query('lang');

        if (is_string($chosen) && in_array($chosen, $available, true)) {
            $request->session()->put(self::SESSION, $chosen);
        }

        $locale = $request->session()->get(self::SESSION);
        App::setLocale(is_string($locale) && in_array($locale, $available, true) ? $locale : ($request->getPreferredLanguage($available) ?? 'en'));

        return $next($request);
    }

    /**
     * @return list<string>
     */
    public static function languages(): array
    {
        return array_map('basename', glob(lang_path('*'), GLOB_ONLYDIR) ?: []);
    }
}
