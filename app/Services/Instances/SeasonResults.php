<?php

declare(strict_types=1);

namespace App\Services\Instances;

use App\Libraries\StatisticsLibrary;
use App\Services\SettingsService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The end of a season: the statistics are worked out once more and the
 * best players of each ranking are kept in the base database, where the
 * lobby shows them as the hall of fame (and rewards can be paid from).
 * Bots and the staff do not count, as in the highscore.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class SeasonResults
{
    /** places kept per ranking */
    public const TOP = 10;

    /** ranking => column prefix in users_statistics */
    public const CATEGORIES = [
        'total' => 'total',
        'buildings' => 'buildings',
        'technology' => 'technology',
        'ships' => 'ships',
        'defenses' => 'defenses',
        'military' => 'military',
    ];

    public function __construct(private InstanceContext $context)
    {
    }

    /**
     * Freezes the rankings of the universe; again replaces them.
     */
    public function freeze(Instance $instance, int $now): void
    {
        $rows = $this->context->runIn($instance, function () use ($instance, $now): array {
            (new StatisticsLibrary())->makeStats();
            $adminLevel = app(SettingsService::class)->getInt('stat_admin_level');
            $rows = [];

            foreach (self::CATEGORIES as $category => $column) {
                $rank = 0;

                foreach ($this->best($column, $adminLevel) as $player) {
                    $values = get_object_vars($player);
                    $rows[] = [
                        'universe' => $instance->slug,
                        'category' => $category,
                        'rank' => ++$rank,
                        'user_id' => is_numeric($values['id'] ?? null) ? (int) $values['id'] : 0,
                        'name' => is_string($values['name'] ?? null) ? $values['name'] : '',
                        'alliance_tag' => is_string($values['alliance_tag'] ?? null) ? $values['alliance_tag'] : null,
                        'points' => is_numeric($values['points'] ?? null) ? (float) $values['points'] : 0.0,
                        'frozen_at' => $now,
                    ];
                }
            }

            return $rows;
        });

        $this->table()->where('universe', $instance->slug)->delete();
        $this->table()->insert($rows);
    }

    /**
     * The frozen rankings of a universe, by category, best first.
     *
     * @return array<string, list<array{rank: int, name: string, alliance_tag: string|null, points: float}>>
     */
    public function forUniverse(string $universe): array
    {
        $results = [];

        foreach ($this->table()->where('universe', $universe)->orderBy('category')->orderBy('rank')->get() as $row) {
            $values = get_object_vars($row);
            $category = is_string($values['category'] ?? null) ? $values['category'] : '';
            $results[$category][] = [
                'rank' => is_numeric($values['rank'] ?? null) ? (int) $values['rank'] : 0,
                'name' => is_string($values['name'] ?? null) ? $values['name'] : '',
                'alliance_tag' => is_string($values['alliance_tag'] ?? null) ? $values['alliance_tag'] : null,
                'points' => is_numeric($values['points'] ?? null) ? (float) $values['points'] : 0.0,
            ];
        }

        return $results;
    }

    /**
     * @return list<object>
     */
    private function best(string $column, int $adminLevel): array
    {
        return array_values(DB::table('users_statistics as s')
            ->join('users as u', 'u.id', '=', 's.user_statistic_user_id')
            ->leftJoin('alliance as a', 'a.alliance_id', '=', 'u.ally_id')
            ->where('u.user_is_bot', false)
            ->where('u.authlevel', '<=', $adminLevel)
            ->where('s.user_statistic_' . $column . '_points', '>', 0)
            ->orderByDesc('s.user_statistic_' . $column . '_points')
            ->orderBy('u.id')
            ->limit(self::TOP)
            ->get(['u.id', 'u.name', 'a.alliance_tag', 's.user_statistic_' . $column . '_points as points'])
            ->all());
    }

    private function table(): Builder
    {
        return DB::connection(InstanceRegistry::CONNECTION)->table('season_results');
    }
}
