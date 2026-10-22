<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install\Steps;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FinalController extends RequirementsController
{
    /**
     * The APP_KEY shipped in .env.example, so the same on every server
     * installed from the release package.
     */
    private const PUBLIC_APP_KEY = 'base64:S5umA/0fcvXfOda1+4J+Rh1gLi33kRKQm+FVWMv6OII=';

    /** @var array<string, non-empty-string> */
    protected array $requirements = [
        'install' => 'checkInstallDir',
        'files' => 'checkFilesPermissions',
    ];

    /** @return view-string */
    protected function getView(): string
    {
        return 'install.steps.final';
    }

    /** @return array{requirement: mixed, message: mixed, result: string} */
    protected function checkInstallDir(): array
    {
        $requirement = __('install/install.final_install_not_accessible');
        $message = __('install/install.final_install_not_accessible_ok');
        $result = 'success';

        if (!$this->writeConfigFile()) {
            $this->fail = true;

            $message = __('install/install.final_install_not_accessible_need', ['file' => 'xgp-db-config.php']);
            $result = 'danger';
        }

        return [
            'requirement' => $requirement,
            'message' => $message,
            'result' => $result,
        ];
    }

    /** @return array{requirement: mixed, message: mixed, result: string} */
    protected function checkFilesPermissions(): array
    {
        $requirement = __('install/install.final_config_writable');
        $message = __('install/install.final_config_writable_ok');
        $result = 'success';

        $file = 'xgp-db-config.php';
        $disk = Storage::build([
            'driver' => 'local',
            'root' => config_path(),
        ]);

        $disk->setVisibility($file, 'private');

        if (
            !$disk->exists($file) ||
            $disk->getVisibility($file) === 'public'
        ) {
            $this->fail = true;

            $message = __('install/install.final_config_writable_need', ['file' => $file]);
            $result = 'danger';
        }

        return [
            'requirement' => $requirement,
            'message' => $message,
            'result' => $result,
        ];
    }

    private function writeConfigFile(): bool
    {
        $file = 'xgp-db-config.php';
        $disk = Storage::build([
            'driver' => 'local',
            'root' => config_path(),
        ]);

        if (!$disk->exists($file)) {
            return false;
        }

        $disk->setVisibility($file, 'public');

        // Flip the INSTALLED flag written during the database step inside the existing
        // config() block, instead of appending a second block.
        $content = (string) $disk->get($file);
        $content = str_replace(
            "'INSTALLED' => 'false'",
            "'INSTALLED' => 'true'",
            $content
        );

        $disk->put($file, $content);

        // OPcache may keep serving the previous compiled version of this file to other
        // processes (e.g. php-fpm); config:clear does not touch it, so invalidate it here.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate(config_path($file), true);
        }

        Artisan::call('cache:clear');
        Artisan::call('config:clear');

        // wipe install progress so the flow can be started fresh next time
        session()->flush();

        $this->replacePublicAppKey();

        return true;
    }

    /**
     * Give the server its own APP_KEY (it signs and encrypts cookies and
     * sessions). Security fix: every installation from the release package
     * kept the public key of .env.example. Done at the very end, since a new
     * key ends the installer's session.
     */
    private function replacePublicAppKey(): void
    {
        $key = config('app.key');

        if (is_string($key) && $key !== '' && $key !== self::PUBLIC_APP_KEY) {
            return;
        }

        try {
            Artisan::call('key:generate', ['--force' => true]);
        } catch (Throwable $exception) {
            // .env not writable: the installation works, but keeps the public key
            report($exception);
        }
    }
}
