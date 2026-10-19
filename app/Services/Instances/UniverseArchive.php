<?php

declare(strict_types=1);

namespace App\Services\Instances;

use App\Services\Operators\DumpShell;
use App\Services\Operators\OffsiteBackup;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Closed universes for good (xgp:instance:archive): the database is dumped
 * once more, into the backup repository under the tag xgp-archive (the
 * keep rules of the nightly backups never drop it) or into a .sql.gz file,
 * the dump is checked to hold every table, and only then is the database
 * dropped. The universe stays in the registry as archived: the lobby keeps
 * its hall of fame (that lives in the base database). unarchive() brings
 * the database back under its old name, the universe closed again.
 */
class UniverseArchive
{
    private const NAME = '/^[A-Za-z0-9_]{1,64}$/';

    public function __construct(
        private InstanceRegistry $registry,
        private OffsiteBackup $backup,
        private DumpShell $shell,
    ) {
    }

    /**
     * Days a universe stays closed before the scheduler archives it; 0:
     * never on its own.
     */
    public static function afterDays(): int
    {
        $days = config('instances.archive_after_days');

        return is_numeric($days) ? max(0, (int) $days) : 0;
    }

    /**
     * The closed universes the scheduler archives now.
     *
     * @return list<Instance>
     */
    public function due(int $now): array
    {
        $days = self::afterDays();

        if ($days === 0) {
            return [];
        }

        return array_values(array_filter(
            $this->registry->all(),
            static fn (Instance $instance): bool => $instance->status === Instance::CLOSED &&
                ($instance->closedAt ?? $instance->endsAt ?? $now) <= $now - $days * 86400,
        ));
    }

    /**
     * Dumps, checks and drops; returns where the dump lies (restic:<id> or
     * file:<path>). Nothing is dropped when anything before fails.
     *
     * @throws RuntimeException
     */
    public function archive(Instance $instance, int $now, ?string $file = null): string
    {
        if ($instance->status !== Instance::CLOSED) {
            throw new RuntimeException('Only a closed universe is archived; ' . $instance->slug . ' is ' . $instance->status . '.');
        }

        $database = self::name($instance->database);
        $tables = $this->tables($database);

        if ($tables === 0) {
            throw new RuntimeException('The database ' . $database . ' holds no tables: nothing to archive.');
        }

        $where = $file === null ? $this->toRepository($database, $instance->slug, $tables) : $this->toFile($database, $file, $tables);

        // first the registry (the dump is safe), then the database: should
        // dropping fail, the data is there twice, never nowhere
        $this->registry->update($instance->slug, ['status' => Instance::ARCHIVED, 'archived_at' => $now, 'archive' => $where]);
        DB::connection(InstanceRegistry::CONNECTION)->statement('DROP DATABASE `' . $database . '`');

        return $where;
    }

    /**
     * The database back under its old name, from where archive() put it;
     * the universe is closed again (its hall of fame is unchanged).
     *
     * @throws RuntimeException
     */
    public function unarchive(Instance $instance): void
    {
        if ($instance->status !== Instance::ARCHIVED) {
            throw new RuntimeException($instance->slug . ' is not archived.');
        }

        $database = self::name($instance->database);
        [$kind, $place] = array_pad(explode(':', $instance->archive, 2), 2, '');

        match ($kind) {
            'restic' => $this->backup->restore($database, $database, $place),
            'file' => $this->fromFile($place, $database),
            default => throw new RuntimeException('Where the archive of ' . $instance->slug . ' lies is not known.'),
        };

        $this->registry->update($instance->slug, ['status' => Instance::CLOSED, 'archived_at' => null, 'archive' => '']);
    }

    /**
     * @throws RuntimeException
     */
    private function toRepository(string $database, string $slug, int $tables): string
    {
        if (!$this->backup->configured()) {
            throw new RuntimeException('No backup server (XGP_BACKUP_REPOSITORY, XGP_BACKUP_PASSWORD): archive into a file with --file=.');
        }

        $this->shell->openRepository();
        $output = $this->shell->run(DumpShell::DUMP . ' | "$RESTIC" backup --stdin --stdin-filename "$DB.sql" --tag xgp-archive --tag "db:$DB" --tag "universe:$SLUG" --host xgproyect --json', [
            'DB' => $database,
            'SLUG' => $slug,
        ]);
        $snapshot = self::snapshotId($output);
        $found = $this->shell->run('"$RESTIC" dump "$SNAPSHOT" "/$DB.sql" | grep -c "^CREATE TABLE" || true', ['DB' => $database, 'SNAPSHOT' => $snapshot]);
        self::check($database, $tables, $found);

        return 'restic:' . $snapshot;
    }

    /**
     * @throws RuntimeException
     */
    private function toFile(string $database, string $file, int $tables): string
    {
        if ($file === '' || file_exists($file) || !is_dir(dirname($file))) {
            throw new RuntimeException('The file must be new, in a folder that exists: ' . $file);
        }

        $this->shell->run(DumpShell::DUMP . ' | gzip > "$FILE"', ['DB' => $database, 'FILE' => $file]);
        $found = $this->shell->run('gzip -dc "$FILE" | grep -c "^CREATE TABLE" || true', ['FILE' => $file]);
        self::check($database, $tables, $found);

        return 'file:' . realpath($file);
    }

    /**
     * @throws RuntimeException
     */
    private function fromFile(string $file, string $database): void
    {
        if (!is_file($file)) {
            throw new RuntimeException('The archive file is missing: ' . $file);
        }

        $this->create($database);
        $this->shell->run('gzip -dc "$FILE" | ' . DumpShell::LOAD, ['FILE' => $file, 'INTO' => $database]);
    }

    /**
     * @throws RuntimeException
     */
    private function create(string $database): void
    {
        $base = DB::connection(InstanceRegistry::CONNECTION);

        // raw: the table prefix must not reach information_schema
        if ($base->select('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$database]) !== []) {
            throw new RuntimeException('The database ' . $database . ' exists already.');
        }

        $base->statement('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    private function tables(string $database): int
    {
        $row = DB::connection(InstanceRegistry::CONNECTION)
            ->selectOne('SELECT COUNT(*) AS tables FROM information_schema.tables WHERE table_schema = ?', [$database]);

        return is_object($row) && is_numeric($row->tables ?? null) ? (int) $row->tables : 0;
    }

    /**
     * @throws RuntimeException
     */
    private static function check(string $database, int $tables, string $found): void
    {
        if ((int) trim($found) !== $tables) {
            throw new RuntimeException('The dump of ' . $database . ' holds ' . (int) trim($found) . ' of ' . $tables . ' tables: the database was not dropped.');
        }
    }

    /**
     * The id restic prints in the summary of `backup --json`.
     *
     * @throws RuntimeException
     */
    private static function snapshotId(string $output): string
    {
        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            $message = json_decode($line, true);

            if (is_array($message) && ($message['message_type'] ?? null) === 'summary' && is_string($message['snapshot_id'] ?? null)) {
                return $message['snapshot_id'];
            }
        }

        throw new RuntimeException('restic did not name the snapshot.');
    }

    /**
     * @throws RuntimeException
     */
    private static function name(string $database): string
    {
        if (preg_match(self::NAME, $database) !== 1) {
            throw new RuntimeException('Not a database name: ' . $database);
        }

        return $database;
    }
}
