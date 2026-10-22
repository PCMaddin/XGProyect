<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Artisan;

/**
 * Base test case for tests that need the game schema in a throwaway
 * in-memory SQLite database. The schema is defined by the install migrations
 * (database/migrations/install), the same ones the installer runs, so tests
 * exercise the real table structure.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--path' => 'database/migrations/install']);
    }
}
