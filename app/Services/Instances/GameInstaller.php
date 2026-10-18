<?php

declare(strict_types=1);

namespace App\Services\Instances;

use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The game in the base database as the web installer sets it up, with an
 * administrator, for servers set up from the command line (containers).
 */
class GameInstaller
{
    public function __construct(
        private Artisan $artisan,
        private AdminAccount $admins,
        private InstanceProvisioner $provisioner,
    ) {
    }

    /**
     * Whether the base database holds a game.
     */
    public function installed(): bool
    {
        return DB::connection()->getSchemaBuilder()->hasTable('migrations');
    }

    /**
     * False when the database holds a game already: nothing is changed.
     * Only into an empty database: what a failed installation left is
     * removed again, so the next attempt starts clean.
     */
    public function install(string $adminName, string $adminEmail, string $adminPassword): bool
    {
        if ($this->installed()) {
            return false;
        }

        $connection = DB::connection();

        if ($connection->getSchemaBuilder()->getTables($connection->getDatabaseName()) !== []) {
            throw new RuntimeException('The database holds tables but no game: install into an empty database.');
        }

        try {
            foreach (InstanceProvisioner::INSTALL as [$command, $parameters]) {
                $this->run($command, $parameters);
            }

            // the options were read before the seeders wrote them
            app()->forgetScopedInstances();
            $this->admins->create($adminName, $adminEmail, $adminPassword);
            $this->provisioner->prepareRegistry();
        } catch (Throwable $e) {
            $this->run('db:wipe', ['--force' => true]);

            throw $e;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function run(string $command, array $parameters): void
    {
        if ($this->artisan->call($command, $parameters) !== 0) {
            throw new RuntimeException($command . ' failed: ' . $this->artisan->output());
        }
    }
}
