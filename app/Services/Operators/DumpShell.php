<?php

declare(strict_types=1);

namespace App\Services\Operators;

use App\Services\Instances\InstanceRegistry;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs the shell lines of backups and archives (mariadb-dump, restic, the
 * client). The values reach the shell as environment variables, never in
 * the command line; a pipe that breaks off anywhere fails (pipefail), so a
 * half dump never counts.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class DumpShell
{
    /** a database to standard output; $DB names it */
    public const DUMP = '"$DUMP" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --single-transaction --quick'
        . ' --routines --events --triggers --hex-blob --default-character-set=utf8mb4 "$DB"';

    /** standard input into a database; $INTO names it */
    public const LOAD = '"$CLIENT" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$INTO"';

    /**
     * @param array<string, string> $env
     *
     * @throws RuntimeException
     */
    public function run(string $script, array $env = []): string
    {
        $process = new Process(['bash', '-o', 'pipefail', '-c', $script], base_path(), $env + [
            'RESTIC' => self::setting('restic'),
            'DUMP' => self::setting('dump'),
            'CLIENT' => self::setting('client'),
            'RESTIC_REPOSITORY' => self::setting('repository'),
            'RESTIC_PASSWORD' => self::setting('password'),
            'DB_HOST' => self::connection('host'),
            'DB_PORT' => self::connection('port') === '' ? '3306' : self::connection('port'),
            'DB_USER' => self::connection('username'),
            'MYSQL_PWD' => self::connection('password'),
        ], null, 6 * 3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput());

            throw new RuntimeException($error !== '' ? $error : 'exit code ' . $process->getExitCode());
        }

        return $process->getOutput();
    }

    /**
     * A new repository is set up the first time; any other trouble (a wrong
     * password, a server out of reach) is an error.
     *
     * @throws RuntimeException
     */
    public function openRepository(): void
    {
        try {
            $this->run('"$RESTIC" cat config >/dev/null');
        } catch (RuntimeException $e) {
            if (preg_match('/repository does not exist|Is there a repository/i', $e->getMessage()) !== 1) {
                throw $e;
            }

            $this->run('"$RESTIC" init');
        }
    }

    private static function setting(string $key): string
    {
        $value = config('offsite.' . $key);

        return is_string($value) ? $value : '';
    }

    /**
     * The base database's connection: where the dumps are read from.
     */
    private static function connection(string $key): string
    {
        $value = config('database.connections.' . InstanceRegistry::CONNECTION . '.' . $key);

        return is_scalar($value) ? (string) $value : '';
    }
}
