<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::middleware('install')
                ->group(base_path('routes/install.php'));

            Route::middleware('operator')
                ->prefix('operator')
                ->group(base_path('routes/operator.php'));

            // before the game's catch-all route
            Route::middleware('webhook')
                ->group(base_path('routes/webhooks.php'));

            Route::middleware('game')
                ->group(base_path('routes/game.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // signing in to the game or the admin area: few tries per address and
        // per account (against guessing and lists of leaked passwords)
        RateLimiter::for('login', function (Request $request) {
            $account = $request->input('username', $request->input('inputEmail'));

            return [
                Limit::perMinute(20)->by('ip:' . $request->ip()),
                Limit::perMinute(5)->by('account:' . mb_strtolower(is_string($account) ? $account : '')),
            ];
        });

        // new accounts: a few per address and hour (second accounts cost effort)
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(5)->by('ip:' . $request->ip()));

        // reset mails: no flood of mails to an address
        RateLimiter::for('recover', function (Request $request) {
            $email = $request->input('email');

            return [
                Limit::perMinute(5)->by('ip:' . $request->ip()),
                Limit::perHour(5)->by('email:' . mb_strtolower(is_string($email) ? $email : '')),
            ];
        });

        // the operators' sign-in: few tries per address and per account
        RateLimiter::for('operator-login', function (Request $request) {
            $email = $request->input('email');

            return [
                Limit::perMinute(10)->by('ip:' . $request->ip()),
                Limit::perMinute(5)->by('email:' . mb_strtolower(is_string($email) ? $email : '')),
            ];
        });
    }
}
