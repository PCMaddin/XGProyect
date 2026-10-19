<?php

declare(strict_types=1);

namespace App\Services\Operators;

use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArgvInput;
use Throwable;

/**
 * Collects the errors of every universe in the base database, grouped by
 * kind: the class, the place and the message with its numbers and quoted
 * values taken out, so "order 17" and "order 42" are one group. The
 * operators see them on /operator; xgp:errors:notify mails a group once it
 * happened often enough. Recording never throws: it runs while handling an
 * error already, maybe one of the database.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class ErrorMonitor
{
    public const OPEN = 'open';
    public const RESOLVED = 'resolved';
    public const MUTED = 'muted';

    public const STATUSES = [self::OPEN, self::MUTED, self::RESOLVED];

    public const PAGE_SIZE = 50;

    /** resolved groups not seen again for this long are deleted */
    private const KEEP_RESOLVED = 90 * 86400;

    private const TRACE_LENGTH = 8000;

    public function __construct(private InstanceContext $context)
    {
    }

    public function record(Throwable $error, int $now): void
    {
        try {
            if (!Schema::connection(InstanceRegistry::CONNECTION)->hasTable('error_groups')) {
                return;
            }

            $fingerprint = self::fingerprint($error);
            $seen = [
                'last_seen' => $now,
                'universe' => $this->context->current()->slug ?? '',
                'context' => mb_substr(self::context(), 0, 300),
            ];

            if ($this->again($fingerprint, $seen)) {
                return;
            }

            $inserted = $this->table()->insertOrIgnore($seen + [
                'fingerprint' => $fingerprint,
                'class' => mb_substr($error::class, 0, 190),
                'message' => mb_substr($error->getMessage(), 0, 2000),
                'file' => mb_substr(self::relative($error->getFile()), 0, 300),
                'line' => max(0, $error->getLine()),
                'trace' => mb_substr($error->getTraceAsString(), 0, self::TRACE_LENGTH),
                'first_seen' => $now,
            ]);

            if ($inserted === 0) {
                // another request of the same error was first
                $this->again($fingerprint, $seen);
            }
        } catch (Throwable) {
            // nothing more to do: the log has the error anyway
        }
    }

    /**
     * The newest first, of one status or all.
     *
     * @return list<object>
     */
    public function groups(?string $status, int $offset = 0): array
    {
        $query = $this->table()->orderByDesc('last_seen')->offset($offset)->limit(self::PAGE_SIZE)
            ->select(['id', 'class', 'message', 'file', 'line', 'universe', 'context', 'occurrences', 'status', 'first_seen', 'last_seen']);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query->get()->all());
    }

    public function find(int $id): ?object
    {
        return $this->table()->where('id', $id)->first();
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        foreach ($this->table()->selectRaw('status, count(*) as groups_counted')->groupBy('status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->groups_counted;
        }

        return $counts;
    }

    public function setStatus(int $id, string $status): bool
    {
        return in_array($status, self::STATUSES, true) &&
            $this->table()->where('id', $id)->update(['status' => $status, 'pending' => 0]) === 1;
    }

    /**
     * The open groups that happened at least $threshold times since the
     * last mail about them, at most one mail an hour each; they count from
     * zero again.
     *
     * @return list<object>
     */
    public function takeDue(int $threshold, int $now): array
    {
        $due = $this->table()
            ->where('status', self::OPEN)
            ->where('pending', '>=', max(1, $threshold))
            ->where(fn (Builder $query) => $query->whereNull('notified_at')->orWhere('notified_at', '<', $now - 3600))
            ->orderByDesc('pending')
            ->get()
            ->all();

        if ($due !== []) {
            $this->table()->whereIn('id', array_map(static fn (object $group): mixed => $group->id, $due))->update(['notified_at' => $now, 'pending' => 0]);
        }

        return array_values($due);
    }

    /**
     * Deletes resolved groups that did not come back for 90 days.
     */
    public function prune(int $now): int
    {
        return $this->table()->where('status', self::RESOLVED)->where('last_seen', '<', $now - self::KEEP_RESOLVED)->delete();
    }

    /**
     * The kind of an error: class, place and the message without what
     * changes from one time to the next (numbers, quoted values).
     */
    public static function fingerprint(Throwable $error): string
    {
        $message = (string) preg_replace(['/([\'"`]).*?\1/s', '/\d+/'], ['?', 'N'], $error->getMessage());

        return sha1($error::class . '|' . self::relative($error->getFile()) . '|' . $error->getLine() . '|' . mb_substr($message, 0, 300));
    }

    /**
     * @param array<string, mixed> $seen
     */
    private function again(string $fingerprint, array $seen): bool
    {
        return $this->table()->where('fingerprint', $fingerprint)->update($seen + [
            'occurrences' => DB::raw('occurrences + 1'),
            'pending' => DB::raw('pending + 1'),
            // a resolved error that comes back is open again
            'status' => DB::raw("case when status = '" . self::RESOLVED . "' then '" . self::OPEN . "' else status end"),
        ]) === 1;
    }

    /**
     * Where it happened: the request (method and path, never the query: it
     * may hold tokens) or the command.
     */
    private static function context(): string
    {
        // tests run in the console too, with requests of their own
        if (!app()->runningInConsole() || app()->runningUnitTests()) {
            $request = request();

            return $request->method() . ' /' . ltrim($request->path(), '/');
        }

        return 'artisan ' . ((new ArgvInput())->getFirstArgument() ?? '');
    }

    private static function relative(string $file): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    private function table(): Builder
    {
        return DB::connection(InstanceRegistry::CONNECTION)->table('error_groups');
    }
}
