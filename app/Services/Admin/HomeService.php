<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Support\Concerns\ReadsMixedValues;
use App\Services\FormatService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class HomeService
{
    use ReadsMixedValues;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly FormatService $formatService,
    ) {
    }

    public function hasServerErrors(): bool
    {
        return file_exists(storage_path('logs/xgproyect.log'));
    }

    /**
     * Debug mode shows stack traces, queries and request data to every
     * visitor whose request fails; only for local development.
     */
    public function isDebugModeOn(): bool
    {
        return (bool) config('app.debug');
    }

    public function isInstallFilePresent(): bool
    {
        return file_exists(public_path('install.php'));
    }

    public function isConfigFileWorldWritable(): bool
    {
        $file = config_path('xgp-db-config.php');

        return file_exists($file) && (fileperms($file) & 0x0002) !== 0;
    }

    public function isVersionOutdated(): bool
    {
        try {
            $response = Http::timeout(1)->get('https://updates.xgproyect.org/latest.json');

            if ($response->successful()) {
                $latestVersion = $response->json('version');

                return is_string($latestVersion) &&
                    version_compare($this->settings->getString('version'), $latestVersion, '<');
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    public function hasPendingUpdate(): bool
    {
        return $this->settings->getString('version') !== config('version.files');
    }

    public function getDbVersion(): string
    {
        return self::asString(DB::scalar('SELECT @@version'));
    }

    public function getDbSize(): string
    {
        return $this->formatService->prettyBytes(
            self::asInt(DB::scalar(
                'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = ?',
                [DB::getDatabaseName()]
            ))
        );
    }
}
