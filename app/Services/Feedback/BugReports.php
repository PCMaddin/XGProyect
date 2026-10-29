<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Models\User;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What players report from the game (base database, all universes): the
 * description, the page, the universe, the player and the browser. The
 * operators set each one to seen and fixed on /operator.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class BugReports
{
    public const NEW = 'new';
    public const SEEN = 'seen';
    public const FIXED = 'fixed';

    public const STATUSES = [self::NEW, self::SEEN, self::FIXED];

    public const MAX_LENGTH = 5000;

    public const PAGE_SIZE = 50;

    /** a fixed report is deleted after this many days (it names a player and a browser) */
    public const KEEP_FIXED_DAYS = 90;

    public function __construct(private InstanceContext $context)
    {
    }

    public function submit(User $user, string $page, string $description, string $userAgent, int $now): int
    {
        return $this->table()->insertGetId([
            'universe' => $this->context->current()->slug ?? '',
            'user_id' => $user->id,
            'user_name' => mb_substr($user->name, 0, 64),
            'page' => mb_substr(self::page($page), 0, 500),
            'description' => mb_substr(trim($description), 0, self::MAX_LENGTH),
            'user_agent' => mb_substr($userAgent, 0, 300),
            'status' => self::NEW,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The newest first, of one status or all.
     *
     * @return list<object>
     */
    public function latest(?string $status, int $offset = 0): array
    {
        $query = $this->table()->orderByDesc('id')->offset($offset)->limit(self::PAGE_SIZE);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query->get()->all());
    }

    /**
     * How many there are of each status.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        foreach ($this->table()->selectRaw('status, count(*) as reports')->groupBy('status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->reports;
        }

        return $counts;
    }

    public function setStatus(int $id, string $status, int $now): bool
    {
        return in_array($status, self::STATUSES, true) &&
            $this->table()->where('id', $id)->update(['status' => $status, 'updated_at' => $now]) === 1;
    }

    /**
     * Only a path of this site (no other host, no scheme): the page the
     * player came from.
     */
    /**
     * Deletes the reports fixed before the given time.
     */
    public function prune(int $now): int
    {
        return $this->table()->where('status', self::FIXED)->where('updated_at', '<', $now - self::KEEP_FIXED_DAYS * 86400)->delete();
    }

    public static function page(string $page): string
    {
        $path = parse_url($page, PHP_URL_PATH);
        $query = parse_url($page, PHP_URL_QUERY);

        $foreign = parse_url($page, PHP_URL_SCHEME) !== null || parse_url($page, PHP_URL_HOST) !== null;

        if ($foreign || !is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '';
        }

        return $path . (is_string($query) && $query !== '' ? '?' . $query : '');
    }

    private function table(): Builder
    {
        return DB::connection(InstanceRegistry::CONNECTION)->table('bug_reports');
    }
}
