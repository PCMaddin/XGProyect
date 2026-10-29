<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GameObjectRegistry::class);
        // the options are read many times per request (the tick alone about
        // 15 times); load them once per request or job, not per use
        $this->app->scoped(SettingsService::class);
        // the universe of the request: see SelectInstance
        $this->app->singleton(InstanceContext::class);
        $this->app->singleton(InstanceRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // the registry of the universes stays on the base database, whichever universe is played
        $default = config('database.default');

        if (config('database.connections.central') === null && is_string($default)) {
            config(['database.connections.central' => config('database.connections.' . $default)]);
        }

        // the game layout shows the game name as the page title; pages that
        // did not pass it (the overview among them) failed to render
        ViewFactory::composer('master.game', function (View $view): void {
            if (!array_key_exists('gameTitle', $view->getData())) {
                $view->with('gameTitle', $this->app->make(SettingsService::class)->getString('game_name'));
            }
        });
    }
}
