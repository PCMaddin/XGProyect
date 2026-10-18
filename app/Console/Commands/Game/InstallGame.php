<?php

declare(strict_types=1);

namespace App\Console\Commands\Game;

use App\Services\Instances\GameInstaller;
use Illuminate\Console\Command;
use Throwable;

/**
 * The installer without a browser: the game in the database of the
 * configuration, with an administrator. A database that holds a game is
 * left as it is, so a deploy script can run it every time.
 */
class InstallGame extends Command
{
    /** @var string */
    protected $signature = 'xgp:install
        {--admin-name= : the administrator\'s name in the game}
        {--admin-email=}
        {--admin-password= : instead of asking (avoid: it stays in the shell history)}';

    /** @var string */
    protected $description = 'Install the game in the configured database (does nothing when it is installed)';

    public function handle(GameInstaller $installer): int
    {
        if (config('INSTALLED') !== 'true') {
            $this->error('The game is not configured: set the database and INSTALLED in config/xgp-db-config.php (the container writes it from the environment).');

            return self::FAILURE;
        }

        if ($installer->installed()) {
            $this->info('The database holds a game already; nothing changed.');

            return self::SUCCESS;
        }

        try {
            $installer->install(
                self::str($this->option('admin-name')) ?: self::str($this->ask('Administrator name')),
                self::str($this->option('admin-email')) ?: self::str($this->ask('Administrator e-mail')),
                self::str($this->option('admin-password')) ?: self::str($this->secret('Administrator password')),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('The game is installed.');

        return self::SUCCESS;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
