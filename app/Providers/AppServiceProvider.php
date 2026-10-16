<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\GameObjects\GameObjectRegistry;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // the game layout shows the game name as the page title; pages that
        // did not pass it (the overview among them) failed to render
        ViewFactory::composer('master.game', function (View $view): void {
            if (!array_key_exists('gameTitle', $view->getData())) {
                $view->with('gameTitle', $this->app->make(SettingsService::class)->getString('game_name'));
            }
        });
    }
}
