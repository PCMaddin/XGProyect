<?php

declare(strict_types=1);

namespace App\Services\Operators;

use App\Services\Instances\InstanceRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Backups on another server (config/offsite.php): each database, the base
 * one and every universe's, dumped with mariadb-dump straight into a
 * restic repository (encrypted, only what changed is sent), one snapshot
 * per database tagged db:<name>; old snapshots go by the keep rules. A
 * universe comes back on its own with restore(), into a new database.
 *
 * The shell lines run through DumpShell (values as environment variables,
 * pipefail: a half dump never counts as a backup).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class OffsiteBackup
{
    public const OK = 'ok';
    public const FAILED = 'failed';
    public const RUNNING = 'running';

    public function __construct(private InstanceRegistry $registry, private DumpShell $shell)
    {
    }

    public function configured(): bool
    {
        return self::setting('repository') !== '' && self::setting('password') !== '';
    }

    /**
     * The base database and those of all universes, open or closed (an
     * archived one has its own snapshot and no database any more).
     *
     * @return list<string>
     */
    public function databases(): array
    {
        $databases = [self::connection('database')];

        foreach ($this->registry->withDatabase() as $instance) {
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
            $this->shell->openRepository();

            foreach ($databases as $database) {
                $this->shell->run(DumpShell::DUMP . ' | "$RESTIC" backup --stdin --stdin-filename "$DB.sql" --tag xgp --tag "db:$DB" --host xgproyect --quiet', ['DB' => $database]);
            }

            $this->shell->run('"$RESTIC" forget --tag xgp --group-by tags --keep-daily "$DAILY" --keep-weekly "$WEEKLY" --keep-monthly "$MONTHLY" --prune --quiet', [
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
        $json = json_decode($this->shell->run('"$RESTIC" snapshots --tag xgp --json'), true);
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

        $this->shell->run('"$RESTIC" dump --tag "db:$DB" "$SNAPSHOT" "/$DB.sql" | ' . DumpShell::LOAD, [
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
