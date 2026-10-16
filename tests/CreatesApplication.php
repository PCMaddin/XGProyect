<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // An installed game stores its database in config/xgp-db-config.php,
        // which is loaded after config/database.php and would point the tests
        // at the game's database: they insert, change and delete rows there.
        // The tests always run on in-memory SQLite, as phpunit.xml intends.
        $app['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'INSTALLED' => 'false',
        ]);

        return $app;
    }
}
