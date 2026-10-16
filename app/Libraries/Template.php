<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Exceptions\LegacyView;
use App\Services\SettingsService;
use Illuminate\Support\Facades\View;

/**
 * Blade rendering helpers for the legacy-shaped libraries.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
abstract class Template
{
    /**
     * Render a view as the whole response (via the LegacyView exception).
     *
     * @deprecated use view() helper whenever possible.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $mergeData
     */
    public static function legacyView(string $view, array $data = [], array $mergeData = []): never
    {
        View::share('gameTitle', app(SettingsService::class)->getString('game_name'));

        throw new LegacyView(View::make($view, $data, $mergeData));
    }

    /**
     * Render a Blade template to HTML; an empty string if the template does
     * not exist (the legacy class returned null, which crashed jsReady()).
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $template = '', array $data = []): string
    {
        $bladeFile = resource_path('views') . DIRECTORY_SEPARATOR . strtr($template, ['/' => DIRECTORY_SEPARATOR, '.' => DIRECTORY_SEPARATOR]) . '.blade.php';

        if (!file_exists($bladeFile)) {
            return '';
        }

        View::share('gameTitle', app(SettingsService::class)->getString('game_name'));

        return View::make($template, $data)->render();
    }

    /**
     * Removes special chars like tabs, new lines and carriage returns: the
     * non-empty lines, trimmed and joined.
     */
    public static function jsReady(string $template = ''): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $template));
        $joined = '';

        foreach ($lines as $line) {
            if (!empty($line)) {
                $joined .= trim($line);
            }
        }

        return $joined;
    }
}
