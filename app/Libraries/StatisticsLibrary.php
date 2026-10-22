<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Core\GameObjects\GameObjectInterface;
use App\Core\GameObjects\GameObjectRegistry;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use App\Libraries\Statistics\BulkStatistics;

/**
 * Rebuilds the per-user and per-alliance statistics points and ranks: all of
 * them at once in the database (Statistics\BulkStatistics), one player's
 * points of one kind here (after an admin changed them).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class StatisticsLibrary
{
    private const USER_POINT_COLUMNS = [
        'buildings' => 'user_statistic_buildings_points',
        'defenses' => 'user_statistic_defenses_points',
        'ships' => 'user_statistic_ships_points',
        'research' => 'user_statistic_technology_points',
    ];

    private const PLANET_POINT_TABLES = [
        'buildings' => 'building',
        'defenses' => 'defense',
        'ships' => 'ship',
    ];

    private static ?GameObjectRegistry $registry = null;

    private int $time = 0;
    private ?int $pointDivisor = null;

    public static function calculatePoints(int | string $element, int | string $level, string $type = ''): int
    {
        $level = (int) $level;
        $currentLevel = $type === 'tech' ? $level : max($level - 1, 0);

        $price = self::registry()->get((int) $element)->getPrice();
        $resourcesTotal = $price->getMetal() + $price->getCrystal() + $price->getDeuterium();
        $levelMultiplier = pow($price->getFactor(), $currentLevel);
        $points = ($resourcesTotal * $levelMultiplier) / max(1, app(SettingsService::class)->getInt('stat_points'));

        return (int) $points;
    }

    private static function registry(): GameObjectRegistry
    {
        if (self::$registry === null) {
            self::$registry = app(GameObjectRegistry::class);
        }

        return self::$registry;
    }

    /**
     * Rebuild the user points for a specific structure type. The planet id is
     * accepted for call-site compatibility but not used — points are always
     * recomputed for the whole user.
     */
    public function rebuildPoints(int $userId, int $planetId, string $what): bool
    {
        unset($planetId);

        if (!isset(self::USER_POINT_COLUMNS[$what])) {
            return false;
        }

        DB::table('users_statistics')->updateOrInsert(
            ['user_statistic_user_id' => $userId],
            $this->calculateSingleCategoryPointColumns($userId, $what)
        );

        return true;
    }

    /**
     * The points of every player, computed by the database.
     */
    public function rebuildAllPoints(): void
    {
        $this->bulk()->refreshPlayerPoints();
    }

    /**
     * @return array<string, float>
     */
    private function calculateSingleCategoryPointColumns(int $userId, string $category): array
    {
        $points = [self::USER_POINT_COLUMNS[$category] => $this->calculateUserCategoryPoints($userId, $category)];

        if (in_array($category, ['ships', 'defenses'], true)) {
            $points['user_statistic_military_points'] = $this->calculateUserCategoryPoints($userId, 'ships')
                + $this->calculateUserCategoryPoints($userId, 'defenses');
        }

        return $points;
    }

    private function calculateUserCategoryPoints(int $userId, string $category): float
    {
        if ($category === 'research') {
            $row = DB::table('research')
                ->where('research_user_id', $userId)
                ->first();

            return $row === null ? 0.0 : $this->calculateRowsPoints([(array) $row]);
        }

        if (!isset(self::PLANET_POINT_TABLES[$category])) {
            return 0.0;
        }

        $singular = self::PLANET_POINT_TABLES[$category];
        $rows = DB::table($category)
            ->join('planets', $category . '.' . $singular . '_planet_id', '=', 'planets.planet_id')
            ->where('planets.planet_user_id', $userId)
            ->where('planets.planet_destroyed', 0)
            ->select($category . '.*')
            ->get()
            ->map(static fn ($row): array => (array) $row);

        return $this->calculateRowsPoints($rows);
    }

    /**
     * @param iterable<array<string, mixed>|object> $rows
     */
    private function calculateRowsPoints(iterable $rows): float
    {
        $points = 0.0;
        $objects = self::registry()->all();

        foreach ($rows as $row) {
            $points += $this->calculateRowPoints((array) $row, $objects);
        }

        return $points;
    }

    /**
     * @param array<string, mixed> $row
     * @param iterable<int, GameObjectInterface> $objects
     */
    private function calculateRowPoints(array $row, iterable $objects): float
    {
        $points = 0.0;

        foreach ($objects as $object) {
            $column = $object->getName();

            if (!isset($row[$column])) {
                continue;
            }

            $points += $this->calculateObjectPoints($object->getId(), $this->toInt($row[$column]));
        }

        return $points;
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function calculateObjectPoints(int $objectId, int $level): float
    {
        if ($level <= 0) {
            return 0.0;
        }

        $price = self::registry()->get($objectId)->getPrice();
        $resourcesTotal = $price->getMetal() + $price->getCrystal() + $price->getDeuterium();
        $factor = $price->getFactor();
        $multiplier = ($factor === 1.0)
            ? $level
            : (pow($factor, $level) - 1) / ($factor - 1);

        return ($resourcesTotal * $multiplier) / $this->pointDivisor();
    }

    private function pointDivisor(): int
    {
        if ($this->pointDivisor === null) {
            $this->pointDivisor = max(1, app(SettingsService::class)->getInt('stat_points'));
        }

        return $this->pointDivisor;
    }

    /**
     * Points and ranks of all players and alliances. Writes the number of
     * ranked players to the option stat_users (the pages read it instead of
     * counting the players on every view).
     *
     * @return array{
     *     initial_memory: array{0: float, 1: float},
     *     stats_time: int,
     *     totaltime: float,
     *     memory_peak: array{0: float, 1: float},
     *     end_memory: array{0: float, 1: float}
     * }
     */
    public function makeStats(): array
    {
        $this->time = time();
        $starttime = microtime(true);

        $result = [];
        $result['initial_memory'] = [round(memory_get_usage() / 1024, 1), round(memory_get_usage(true) / 1024, 1)];

        $settings = app(SettingsService::class);
        $adminLevel = $settings->getInt('stat_admin_level');
        $bulk = $this->bulk();

        $bulk->refreshPlayerPoints();
        $settings->write('stat_users', $bulk->rankPlayers($adminLevel, $this->time));
        $bulk->rankAlliances($adminLevel, $this->time);

        $endtime = microtime(true);

        $result['stats_time'] = $this->time;
        $result['totaltime'] = ($endtime - $starttime);
        $result['memory_peak'] = [round(memory_get_peak_usage() / 1024, 1), round(memory_get_peak_usage(true) / 1024, 1)];
        $result['end_memory'] = [round(memory_get_usage() / 1024, 1), round(memory_get_usage(true) / 1024, 1)];

        return $result;
    }

    private function bulk(): BulkStatistics
    {
        return new BulkStatistics(self::registry(), $this->pointDivisor());
    }
}
