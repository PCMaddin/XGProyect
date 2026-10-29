<?php

declare(strict_types=1);

namespace App\Libraries\Statistics;

use App\Core\GameObjects\GameObjectRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The points and ranks of all players and alliances in a few statements.
 *
 * The rebuild ran several queries per player and then sent one statement
 * holding every player: with a million players hours of queries, a statement
 * far beyond max_allowed_packet and gigabytes of PHP arrays. Every page view
 * started it again after the time limit had killed the last one, so the
 * universe stopped answering.
 *
 * Now the database computes the points (one INSERT … SELECT over the planets
 * and the research), and a rank is the position at which a player is
 * inserted, sorted by points, into a temporary table with an auto-increment
 * key; this needs no window functions, so it runs on MySQL 5.7, MariaDB and
 * SQLite alike. The tables are updated in slices of player ids, so a page
 * view that changes a player's points waits for one slice, never for all.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class BulkStatistics
{
    /** players or alliances written by one statement */
    public const SLICE = 10000;

    /** ranking => [points column, rank column] in users_statistics */
    public const PLAYER_RANKINGS = [
        'technology' => 'user_statistic_technology',
        'buildings' => 'user_statistic_buildings',
        'defenses' => 'user_statistic_defenses',
        'ships' => 'user_statistic_ships',
        'military' => 'user_statistic_military',
        'honor' => 'user_statistic_honor',
        'military_built' => 'user_statistic_military_built',
        'military_destroyed' => 'user_statistic_military_destroyed',
        'military_lost' => 'user_statistic_military_lost',
        'total' => 'user_statistic_total',
    ];

    private const ALLIANCE_RANKINGS = ['technology', 'buildings', 'defenses', 'ships', 'military', 'total'];

    private const PLANET_TABLES = ['buildings' => 'building', 'defenses' => 'defense', 'ships' => 'ship'];

    public function __construct(
        private GameObjectRegistry $registry,
        private int $pointDivisor,
    ) {
    }

    /**
     * The points of every player from what stands on their planets and from
     * their research; military = ships + defenses, total = buildings +
     * military + research.
     */
    public function refreshPlayerPoints(): void
    {
        DB::statement(
            'INSERT INTO ' . $this->table('users_statistics') . ' (`user_statistic_user_id`)
            SELECT u.`id` FROM ' . $this->table('users') . ' AS u
            WHERE NOT EXISTS (SELECT 1 FROM ' . $this->table('users_statistics') . ' AS s WHERE s.`user_statistic_user_id` = u.`id`)'
        );

        $this->temporary('stat_points', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();

            foreach (['buildings', 'defenses', 'ships', 'research'] as $column) {
                $table->double($column)->default(0);
            }
        });

        $sources = [];
        $columns = [];

        foreach (array_keys(self::PLANET_TABLES) as $category) {
            $sources[] = 'LEFT JOIN (' . $this->planetPoints($category) . ') AS ' . $category . ' ON ' . $category . '.`user_id` = u.`id`';
            $columns[] = 'COALESCE(' . $category . '.`points`, 0)';
        }

        DB::statement(
            'INSERT INTO ' . $this->table('stat_points') . ' (`id`, `buildings`, `defenses`, `ships`, `research`)
            SELECT u.`id`, ' . implode(', ', $columns) . ', COALESCE(research.`points`, 0)
            FROM ' . $this->table('users') . ' AS u
            ' . implode("\n", $sources) . '
            LEFT JOIN (' . $this->researchPoints() . ') AS research ON research.`user_id` = u.`id`'
        );

        $this->updateFrom('users_statistics', 'user_statistic_user_id', ['s' => 'stat_points'], [
            'user_statistic_buildings_points' => 's.`buildings`',
            'user_statistic_defenses_points' => 's.`defenses`',
            'user_statistic_ships_points' => 's.`ships`',
            'user_statistic_military_points' => 's.`ships` + s.`defenses`',
            'user_statistic_technology_points' => 's.`research`',
            'user_statistic_total_points' => 's.`buildings` + s.`ships` + s.`defenses` + s.`research`',
        ]);

        Schema::dropIfExists('stat_points');
    }

    /**
     * The ranks of the players up to the given authority level; the former
     * rank becomes the old one. Returns how many players are ranked.
     */
    public function rankPlayers(int $adminLevel, int $time): int
    {
        $this->temporary('stat_players', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();

            foreach (array_keys(self::PLAYER_RANKINGS) as $ranking) {
                $table->double($ranking)->default(0);
            }
        });

        DB::statement(
            'INSERT INTO ' . $this->table('stat_players') . ' (`id`, `' . implode('`, `', array_keys(self::PLAYER_RANKINGS)) . '`)
            SELECT s.`user_statistic_user_id`, ' . implode(', ', array_map(
                static fn (string $prefix): string => 's.`' . $prefix . '_points`',
                self::PLAYER_RANKINGS
            )) . '
            FROM ' . $this->table('users_statistics') . ' AS s
            INNER JOIN ' . $this->table('users') . ' AS u ON u.`id` = s.`user_statistic_user_id`
            WHERE u.`authlevel` <= ' . $adminLevel
        );

        $ranked = $this->rank('users_statistics', 'user_statistic_user_id', 'stat_players', self::PLAYER_RANKINGS);
        $this->updateFrom('users_statistics', 'user_statistic_user_id', [], ['user_statistic_update_time' => (string) $time]);
        Schema::dropIfExists('stat_players');

        return $ranked;
    }

    /**
     * The points of each alliance (the sum of its ranked members) and its
     * ranks. Alliances without such members keep what they had.
     */
    public function rankAlliances(int $adminLevel, int $time): void
    {
        DB::statement(
            'INSERT INTO ' . $this->table('alliance_statistics') . ' (`alliance_statistic_alliance_id`)
            SELECT a.`alliance_id` FROM ' . $this->table('alliance') . ' AS a
            WHERE NOT EXISTS (SELECT 1 FROM ' . $this->table('alliance_statistics') . ' AS s WHERE s.`alliance_statistic_alliance_id` = a.`alliance_id`)'
        );

        $this->temporary('stat_alliances', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();

            foreach (self::ALLIANCE_RANKINGS as $column) {
                $table->double($column)->default(0);
            }
        });

        DB::statement(
            'INSERT INTO ' . $this->table('stat_alliances') . ' (`id`, `' . implode('`, `', self::ALLIANCE_RANKINGS) . '`)
            SELECT u.`ally_id`, ' . implode(', ', array_map(
                static fn (string $category): string => 'SUM(s.`user_statistic_' . $category . '_points`)',
                self::ALLIANCE_RANKINGS
            )) . '
            FROM ' . $this->table('users') . ' AS u
            INNER JOIN ' . $this->table('users_statistics') . ' AS s ON s.`user_statistic_user_id` = u.`id`
            INNER JOIN ' . $this->table('alliance') . ' AS a ON a.`alliance_id` = u.`ally_id`
            WHERE u.`authlevel` <= ' . $adminLevel . '
            GROUP BY u.`ally_id`'
        );

        $points = ['alliance_statistic_update_time' => (string) $time];
        $rankings = [];

        foreach (self::ALLIANCE_RANKINGS as $category) {
            $points['alliance_statistic_' . $category . '_points'] = 's.`' . $category . '`';
            $rankings[$category] = 'alliance_statistic_' . $category;
        }

        $this->updateFrom('alliance_statistics', 'alliance_statistic_alliance_id', ['s' => 'stat_alliances'], $points);
        $this->rank('alliance_statistics', 'alliance_statistic_alliance_id', 'stat_alliances', $rankings);
        Schema::dropIfExists('stat_alliances');
    }

    /**
     * Ranks the rows of $stage in each ranking (stage column => column
     * prefix in $target): the position at which an id is inserted, sorted
     * by points, into an auto-increment table is its rank. Returns the
     * number of rows ranked.
     *
     * @param array<string, string> $rankings
     */
    private function rank(string $target, string $key, string $stage, array $rankings): int
    {
        $sources = [];
        $previous = [];
        $ranks = [];

        $number = 0;

        foreach ($rankings as $ranking => $prefix) {
            $sources['r' . $number] = 'stat_rank_' . $number;

            $this->temporary('stat_rank_' . $number, function (Blueprint $table): void {
                $table->increments('position');
                $table->unsignedBigInteger('id')->unique();
            });

            DB::statement(
                'INSERT INTO ' . $this->table('stat_rank_' . $number) . ' (`id`)
                SELECT `id` FROM ' . $this->table($stage) . ' ORDER BY `' . $ranking . '` DESC, `id` ASC'
            );

            $previous[$prefix . '_old_rank'] = 't.`' . $prefix . '_rank`';
            $ranks[$prefix . '_rank'] = 'r' . $number . '.`position`';
            $number++;
        }

        // the previous ranks first, in a statement of their own: MySQL does
        // not promise an order for the assignments of a joined update
        $this->updateFrom($target, $key, ['r0' => $sources['r0']], $previous);
        $this->updateFrom($target, $key, $sources, $ranks);

        $ranked = DB::table($stage)->count();

        foreach ($sources as $source) {
            Schema::dropIfExists($source);
        }

        return $ranked;
    }

    /**
     * Sets columns of $target (alias t) from the rows with the same id in
     * each of the $sources (alias => table, key `id`), a slice of ids per
     * statement; without sources from the target's own row.
     *
     * @param array<string, string> $sources
     * @param array<string, string> $values column => expression
     */
    private function updateFrom(string $target, string $key, array $sources, array $values): void
    {
        $first = array_key_first($sources);
        $sliced = $first === null ? 't.`' . $key . '`' : $first . '.`id`';
        $bounds = DB::table($first === null ? $target : $sources[$first])
            ->selectRaw('MIN(`' . ($first === null ? $key : 'id') . '`) AS low, MAX(`' . ($first === null ? $key : 'id') . '`) AS high')
            ->first();
        $low = is_object($bounds) && is_numeric($bounds->low ?? null) ? (int) $bounds->low : 0;
        $high = is_object($bounds) && is_numeric($bounds->high ?? null) ? (int) $bounds->high : -1;

        $assignments = implode(', ', array_map(
            static fn (string $column, string $expression): string => '`' . $column . '` = ' . $expression,
            array_keys($values),
            $values
        ));
        $sql = $this->joinedUpdate($target, $key, $sources, $assignments) . ' ' . $sliced . ' BETWEEN ? AND ?';

        for ($from = $low; $from <= $high; $from += self::SLICE) {
            DB::update($sql, [$from, $from + self::SLICE - 1]);
        }
    }

    /**
     * The update up to its WHERE (the caller adds the slice).
     *
     * @param array<string, string> $sources
     */
    private function joinedUpdate(string $target, string $key, array $sources, string $assignments): string
    {
        $update = 'UPDATE ' . $this->table($target) . ' AS t';
        $conditions = [];

        foreach ($sources as $alias => $source) {
            $conditions[] = $alias . '.`id` = t.`' . $key . '`';
        }

        if ($sources === []) {
            return $update . ' SET ' . $assignments . ' WHERE';
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            return $update . ' SET ' . $assignments . ' FROM ' . implode(', ', array_map(
                fn (string $alias, string $source): string => $this->table($source) . ' AS ' . $alias,
                array_keys($sources),
                $sources
            )) . ' WHERE ' . implode(' AND ', $conditions) . ' AND';
        }

        foreach ($sources as $alias => $source) {
            $update .= ' INNER JOIN ' . $this->table($source) . ' AS ' . $alias . ' ON ' . $alias . '.`id` = t.`' . $key . '`';
        }

        return $update . ' SET ' . $assignments . ' WHERE';
    }

    /**
     * Per player the points of one kind of planet table (intact planets).
     */
    private function planetPoints(string $category): string
    {
        $singular = self::PLANET_TABLES[$category];

        return 'SELECT p.`planet_user_id` AS user_id, SUM(' . $this->pointsExpression($category, 'x') . ') AS points
            FROM ' . $this->table($category) . ' AS x
            INNER JOIN ' . $this->table('planets') . ' AS p ON p.`planet_id` = x.`' . $singular . '_planet_id`
            WHERE p.`planet_destroyed` = 0
            GROUP BY p.`planet_user_id`';
    }

    private function researchPoints(): string
    {
        return 'SELECT r.`research_user_id` AS user_id, SUM(' . $this->pointsExpression('research', 'r') . ') AS points
            FROM ' . $this->table('research') . ' AS r
            GROUP BY r.`research_user_id`';
    }

    /**
     * The points of one row: for every object with a column in the table
     * the cost of all its levels (a geometric series) or units, in points.
     */
    private function pointsExpression(string $tableName, string $alias): string
    {
        $columns = array_flip(Schema::getColumnListing($tableName));
        $terms = [];

        foreach ($this->registry->all() as $object) {
            $column = $object->getName();

            if (!isset($columns[$column])) {
                continue;
            }

            $price = $object->getPrice();
            $resources = $price->getMetal() + $price->getCrystal() + $price->getDeuterium();
            $factor = $price->getFactor();
            $level = $alias . '.`' . $column . '`';
            $multiplier = $factor === 1.0
                ? $level
                : '((POWER(' . self::number($factor) . ', ' . $level . ') - 1) / ' . self::number($factor - 1) . ')';

            $terms[] = 'CASE WHEN ' . $level . ' > 0 THEN ' . self::number($resources) . ' * ' . $multiplier . ' / ' . $this->pointDivisor . ' ELSE 0 END';
        }

        return $terms === [] ? '0' : implode(' + ', $terms);
    }

    /**
     * A temporary table of this connection (dropped first if a failed run
     * left it).
     */
    private function temporary(string $name, \Closure $columns): void
    {
        Schema::dropIfExists($name);
        Schema::create($name, function (Blueprint $table) use ($columns): void {
            $table->temporary();
            $columns($table);
        });
    }

    private function table(string $name): string
    {
        return '`' . DB::getTablePrefix() . $name . '`';
    }

    /**
     * A decimal literal: with integers alone SQLite divides without the
     * fraction.
     */
    private static function number(float | int $value): string
    {
        $literal = rtrim(sprintf('%.17F', $value), '0');

        return str_ends_with($literal, '.') ? $literal . '0' : $literal;
    }
}
