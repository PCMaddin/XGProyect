<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Base test case for tests that need MySQL or MariaDB: whole game pages run
 * SQL that SQLite does not understand (UPDATE … INNER JOIN, DELETE of several
 * tables), and only a real server applies the strict mode the game runs in
 * (ONLY_FULL_GROUP_BY made every game page fail on MariaDB).
 *
 * The tests run against the database named in MYSQL_TEST_DATABASE (wiped
 * first) and are skipped when it is not set; CI starts a MariaDB for them.
 */
abstract class MysqlTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('MYSQL_TEST_DATABASE');

        if (!is_string($database) || $database === '') {
            $this->markTestSkipped('MYSQL_TEST_DATABASE is not set.');
        }

        /** @var array<string, mixed> $mysql */
        $mysql = config('database.connections.mysql');

        config([
            'database.connections.mysql_test' => array_merge($mysql, [
                'host' => getenv('MYSQL_TEST_HOST') ?: '127.0.0.1',
                'port' => getenv('MYSQL_TEST_PORT') ?: '3306',
                'database' => $database,
                'username' => getenv('MYSQL_TEST_USERNAME') ?: 'root',
                'password' => getenv('MYSQL_TEST_PASSWORD') ?: '',
                'prefix' => 'xgp_',
            ]),
            'database.default' => 'mysql_test',
        ]);
        DB::purge('mysql_test');

        Artisan::call('db:wipe', ['--force' => true]);
        Artisan::call('migrate', ['--path' => 'database/migrations/install', '--force' => true]);
    }
}
