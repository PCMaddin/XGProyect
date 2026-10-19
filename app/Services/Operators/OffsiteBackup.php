<?php

declare(strict_types=1);

namespace App\Services\Operators;

use App\Services\Instances\InstanceRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Backups on another server (config/offsite.php): each database, the base
 * one and every universe's, dumped with mariadb-dump straight into a
 * restic repository (encrypted, only what changed is sent), one snapshot
 * per database tagged db:<name>; old snapshots go by the keep rules. A
 * universe comes back on its own with restore(), into a new database.
 *
 * The values reach the shell as environment variables, never in the
 * command line; a dump that breaks off fails the run (pipefail), so a
 * half dump never counts as a backup.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class OffsiteBackup
{
    public const OK = 'ok';
    public const FAILED = 'failed';
    public const RUNNING = 'running';

    private const DUMP = '"$DUMP" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --single-transaction --quick'
        . ' --routines --events --triggers --hex-blob --default-character-set=utf8mb4 "$DB"';

    public function __construct(private InstanceRegistry $registry)
    {
    }

    public function configured(): bool
    {
        return self::setting('repository') !== '' && self::setting('password') !== '';
    }

    /**
     * The base database and those of all universes, open or closed.
     *
     * @return list<string>
     */
    public function databases(): array
    {
        $databases = [self::connection('database')];

        foreach ($this->registry->all() as $instance) {
            $databases[] = $instance->database;
        }

        return array_values(array_unique(array_filter($databases, static fn (string $name): bool => $name !== '')));
    }

    /**
     * Backs up every database and drops the snapshots the keep rules no
     * longer want; a run row tells how it went.
     *
     * @return list<string> the databases backed up
     *
     * @throws RuntimeException
     */
    public function run(int $now): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('XGP_BACKUP_REPOSITORY and XGP_BACKUP_PASSWORD are not set.');
        }

        $databases = $this->databases();
        $run = $this->runs()->insertGetId(['started_at' => $now, 'status' => self::RUNNING, 'databases' => implode(',', $databases), 'message' => '']);

        try {
            $this->openRepository();

            foreach ($databases as $database) {
                $this->shell(self::DUMP . ' | "$RESTIC" backup --stdin --stdin-filename "$DB.sql" --tag xgp --tag "db:$DB" --host xgproyect --quiet', ['DB' => $database]);
            }

            $this->shell('"$RESTIC" forget --tag xgp --group-by tags --keep-daily "$DAILY" --keep-weekly "$WEEKLY" --keep-monthly "$MONTHLY" --prune --quiet', [
                'DAILY' => (string) max(1, self::number('keep_daily')),
                'WEEKLY' => (string) max(0, self::number('keep_weekly')),
                'MONTHLY' => (string) max(0, self::number('keep_monthly')),
            ]);
        } catch (RuntimeException $e) {
            $this->runs()->where('id', $run)->update(['status' => self::FAILED, 'finished_at' => time(), 'message' => mb_substr($e->getMessage(), 0, 2000)]);

            throw $e;
        }

        $this->runs()->where('id', $run)->update(['status' => self::OK, 'finished_at' => time()]);

        return $databases;
    }

    /**
     * The snapshots in the repository, newest first.
     *
     * @return list<array{id: string, time: string, database: string}>
     */
    public function snapshots(): array
    {
        $json = json_decode($this->shell('"$RESTIC" snapshots --tag xgp --json'), true);
        $snapshots = [];

        foreach (is_array($json) ? $json : [] as $snapshot) {
            if (!is_array($snapshot)) {
                continue;
            }

            $tags = is_array($snapshot['tags'] ?? null) ? $snapshot['tags'] : [];
            $database = '';

            foreach ($tags as $tag) {
                if (is_string($tag) && str_starts_with($tag, 'db:')) {
                    $database = substr($tag, 3);
                }
            }

            $snapshots[] = [
                'id' => is_string($snapshot['short_id'] ?? null) ? $snapshot['short_id'] : '',
                'time' => is_string($snapshot['time'] ?? null) ? $snapshot['time'] : '',
                'database' => $database,
            ];
        }

        usort($snapshots, static fn (array $one, array $other): int => strcmp($other['time'], $one['time']));

        return $snapshots;
    }

    /**
     * Brings a database back into a new one (never over a running universe):
     * from the latest snapshot or a given one.
     *
     * @throws RuntimeException
     */
    public function restore(string $database, string $into, string $snapshot = 'latest'): void
    {
        foreach ([$database, $into] as $name) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
                throw new RuntimeException('Not a database name: ' . $name);
            }
        }

        if (preg_match('/^(latest|[0-9a-f]{8,64})$/', $snapshot) !== 1) {
            throw new RuntimeException('Not a snapshot: ' . $snapshot);
        }

        $base = DB::connection(InstanceRegistry::CONNECTION);

        // raw: the table prefix must not reach information_schema
        if ($base->select('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$into]) !== []) {
            throw new RuntimeException('The database ' . $into . ' exists already: restore into a new one.');
        }

        $base->statement('CREATE DATABASE `' . $into . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->shell('"$RESTIC" dump --tag "db:$DB" "$SNAPSHOT" "/$DB.sql" | "$CLIENT" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$INTO"', [
            'DB' => $database,
            'INTO' => $into,
            'SNAPSHOT' => $snapshot,
        ]);
    }

    /**
     * @return list<object>
     */
    public function latestRuns(int $limit = 30): array
    {
        return array_values($this->runs()->orderByDesc('id')->limit($limit)->get()->all());
    }

    /**
     * A new repository is set up on the first run; any other trouble (a
     * wrong password, a server out of reach) is the run's error.
     *
     * @throws RuntimeException
     */
    private function openRepository(): void
    {
        try {
            $this->shell('"$RESTIC" cat config >/dev/null');
        } catch (RuntimeException $e) {
            if (preg_match('/repository does not exist|Is there a repository/i', $e->getMessage()) !== 1) {
                throw $e;
            }

            $this->shell('"$RESTIC" init');
        }
    }

    /**
     * @param array<string, string> $env
     *
     * @throws RuntimeException
     */
    private function shell(string $script, array $env = []): string
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

    private function runs(): \Illuminate\Database\Query\Builder
    {
        return DB::connection(InstanceRegistry::CONNECTION)->table('backup_runs');
    }

    private static function setting(string $key): string
    {
        $value = config('offsite.' . $key);

        return is_string($value) ? $value : '';
    }

    private static function number(string $key): int
    {
        $value = config('offsite.' . $key);

        return is_int($value) ? $value : 0;
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
