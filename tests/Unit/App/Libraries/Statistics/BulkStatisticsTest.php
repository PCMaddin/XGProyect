<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Statistics;

use App\Core\GameObjects\GameObjectRegistry;
use App\Libraries\Statistics\BulkStatistics;
use App\Libraries\StatisticsLibrary;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The statistics of all players in a few statements: the same points as
 * counted one player at a time, ranks by points (the former rank becomes
 * the old one), alliances as the sum of their members, admins left out.
 * The ids lie in different slices of the batched updates.
 */
#[CoversClass(BulkStatistics::class)]
#[CoversClass(StatisticsLibrary::class)]
class BulkStatisticsTest extends DatabaseTestCase
{
    private const LOW = 3;
    private const MIDDLE = 15000;
    private const HIGH = 31000;
    private const ADMIN = 47000;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['stat_points' => '1000', 'stat_admin_level' => '0'] as $name => $value) {
            DB::table('options')->insert(['name' => $name, 'value' => $value, 'type' => 'int']);
        }

        $this->player(self::LOW, 1, 0, 2, 2);
        $this->player(self::MIDDLE, 5, 3, 0, 1);
        $this->player(self::HIGH, 10, 1, 0, 1);
        $this->player(self::ADMIN, 20, 0, 0, 2, 3);
        // a former rank, to become the old one
        DB::table('users_statistics')->where('user_statistic_user_id', self::HIGH)->update(['user_statistic_total_rank' => 3]);
        // no statistics row yet: the rebuild adds it
        DB::table('users_statistics')->where('user_statistic_user_id', self::LOW)->delete();
    }

    public function testPointsAndRanksOfAllPlayers(): void
    {
        (new StatisticsLibrary())->makeStats();

        // the metal mine costs 60 metal + 15 crystal, factor 1.5; light fighters 4 000 each
        $mine = static fn (int $level): float => 75 * (1.5 ** $level - 1) / 0.5 / 1000;
        $energy = $this->points(113, 3);
        $this->assertEqualsWithDelta($mine(10), $this->stat(self::HIGH, 'buildings_points'), 0.000001);
        $this->assertEqualsWithDelta($energy, $this->stat(self::MIDDLE, 'technology_points'), 0.000001);
        $this->assertEqualsWithDelta(8.0, $this->stat(self::LOW, 'ships_points'), 0.000001);
        $this->assertEqualsWithDelta(8.0, $this->stat(self::LOW, 'military_points'), 0.000001);
        $this->assertEqualsWithDelta($mine(5) + $energy, $this->stat(self::MIDDLE, 'total_points'), 0.000001);

        // total: HIGH, MIDDLE, LOW; the admin is not ranked
        $this->assertSame([1.0, 2.0, 3.0, 0.0], array_map(fn (int $id): float => $this->stat($id, 'total_rank'), [self::HIGH, self::MIDDLE, self::LOW, self::ADMIN]));
        $this->assertSame(3.0, $this->stat(self::HIGH, 'total_old_rank'));
        // equal points: the older account first
        $this->assertSame([1.0, 2.0, 3.0], [$this->stat(self::LOW, 'ships_rank'), $this->stat(self::MIDDLE, 'ships_rank'), $this->stat(self::HIGH, 'ships_rank')]);
        $this->assertSame(1.0, $this->stat(self::MIDDLE, 'technology_rank'));
        $this->assertSame(3, app(SettingsService::class)->getInt('stat_users'));

        // alliance 1 (MIDDLE and HIGH) before alliance 2 (LOW; the admin does not count)
        $this->assertEqualsWithDelta($mine(10) + $mine(5), $this->allianceStat(1, 'buildings_points'), 0.000001);
        $this->assertEqualsWithDelta($mine(1) + 8.0, $this->allianceStat(2, 'total_points'), 0.000001);
        $this->assertSame([1.0, 2.0], [$this->allianceStat(1, 'total_rank'), $this->allianceStat(2, 'total_rank')]);
    }

    public function testTheNextRebuildKeepsTheRankAsTheOldOne(): void
    {
        (new StatisticsLibrary())->makeStats();
        DB::table('buildings')->where('building_planet_id', self::LOW)->update(['building_metal_mine' => 30]);

        (new StatisticsLibrary())->makeStats();

        $this->assertSame([1.0, 3.0], [$this->stat(self::LOW, 'total_rank'), $this->stat(self::LOW, 'total_old_rank')]);
        $this->assertSame([2.0, 1.0], [$this->stat(self::HIGH, 'total_rank'), $this->stat(self::HIGH, 'total_old_rank')]);
    }

    public function testADestroyedPlanetCountsNoMore(): void
    {
        DB::table('planets')->where('planet_id', self::HIGH)->update(['planet_destroyed' => time()]);

        (new StatisticsLibrary())->rebuildAllPoints();

        $this->assertSame(0.0, $this->stat(self::HIGH, 'buildings_points'));
    }

    private function player(int $id, int $mine, int $energy, int $fighters, int $alliance = 0, int $authlevel = 0): void
    {
        DB::table('users')->insert(['id' => $id, 'name' => 'player' . $id, 'email' => $id . '@example.com', 'home_planet_id' => $id, 'current_planet' => $id, 'ally_id' => $alliance, 'authlevel' => $authlevel]);
        DB::table('planets')->insert(['planet_id' => $id, 'planet_user_id' => $id, 'planet_galaxy' => 1, 'planet_system' => 1, 'planet_planet' => 1 + $id % 15]);
        DB::table('buildings')->insert(['building_planet_id' => $id, 'building_metal_mine' => $mine]);
        DB::table('ships')->insert(['ship_planet_id' => $id, 'ship_light_fighter' => $fighters]);
        DB::table('defenses')->insert(['defense_planet_id' => $id]);
        DB::table('research')->insert(['research_user_id' => $id, 'research_energy_technology' => $energy]);
        DB::table('users_statistics')->insert(['user_statistic_user_id' => $id]);

        if ($alliance > 0 && !DB::table('alliance')->where('alliance_id', $alliance)->exists()) {
            DB::table('alliance')->insert(['alliance_id' => $alliance, 'alliance_name' => 'A' . $alliance, 'alliance_tag' => 'A' . $alliance]);
        }
    }

    private function points(int $objectId, int $level): float
    {
        $price = (new GameObjectRegistry())->get($objectId)->getPrice();

        return ($price->getMetal() + $price->getCrystal() + $price->getDeuterium()) * (pow($price->getFactor(), $level) - 1) / ($price->getFactor() - 1) / 1000;
    }

    private function stat(int $userId, string $column): float
    {
        $value = DB::table('users_statistics')->where('user_statistic_user_id', $userId)->value('user_statistic_' . $column);
        $this->assertIsNumeric($value);

        return (float) $value;
    }

    private function allianceStat(int $allianceId, string $column): float
    {
        $value = DB::table('alliance_statistics')->where('alliance_statistic_alliance_id', $allianceId)->value('alliance_statistic_' . $column);
        $this->assertIsNumeric($value);

        return (float) $value;
    }
}
