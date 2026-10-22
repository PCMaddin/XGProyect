<?php

declare(strict_types=1);

namespace App\Services\Game\Formulas;

use App\Enums\PlayerClass;
use App\Support\ClassBonuses;

/**
 * Production a planet gets beyond its producers (OGame 7, wiki "Crawler",
 * "Collector"):
 *
 * - collectors: +25 % of the mines' production, +10 % energy;
 * - crawlers: each working crawler adds 0,02 % of the mines' production
 *   (collectors 0,03 %), at most 8 per mine level work (collectors with a
 *   geologist 10 % more), together at most +50 %. Collectors can run them
 *   at up to 150 %. Each uses 50 energy at 100 %, twice that at 150 %;
 * - members of a trader alliance: +5 % of the mines' production and energy.
 */
class ClassProductionService
{
    private const CRAWLERS_PER_MINE_LEVEL = 8;
    private const CRAWLER_BONUS = 0.0002;
    private const CRAWLER_MAX_BONUS = 0.5;
    private const CRAWLER_ENERGY = 50;

    /**
     * The crawlers that work: at most 8 per level of the three mines.
     */
    public function workingCrawlers(int $crawlers, int $mineLevels, PlayerClass $class, bool $geologist): int
    {
        $limit = $mineLevels * self::CRAWLERS_PER_MINE_LEVEL * ($class === PlayerClass::Collector && $geologist ? 1.1 : 1.0);

        return (int) min($crawlers, floor($limit));
    }

    /**
     * The highest crawler setting (in tenths): 15 for collectors, else 10.
     */
    public function maxCrawlerSetting(PlayerClass $class): int
    {
        return $class === PlayerClass::Collector ? 15 : 10;
    }

    /**
     * The extra production per hour.
     *
     * @param array{metal: float, crystal: float, deuterium: float} $minePerHour the mines' own production
     * @param float                                                  $energyBase  energy from plants and satellites
     * @param int                                                    $setting     crawler setting in tenths (10 = 100 %)
     *
     * @return array{metal: float, crystal: float, deuterium: float, energyMax: float, energyUsed: float}
     */
    public function extras(array $minePerHour, float $energyBase, int $crawlers, int $mineLevels, int $setting, ClassBonuses $bonuses, bool $geologist): array
    {
        $class = $bonuses->player;
        $working = $this->workingCrawlers($crawlers, $mineLevels, $class, $geologist);
        $level = max(0, min($setting, $this->maxCrawlerSetting($class))) / 10;
        $crawlerShare = min(
            self::CRAWLER_MAX_BONUS,
            $working * self::CRAWLER_BONUS * ($class === PlayerClass::Collector ? 1.5 : 1.0) * $level
        );
        $share = $bonuses->mineProductionBonus() + $crawlerShare;

        return [
            'metal' => floor($minePerHour['metal'] * $share),
            'crystal' => floor($minePerHour['crystal'] * $share),
            'deuterium' => floor($minePerHour['deuterium'] * $share),
            'energyMax' => floor($energyBase * $bonuses->energyProductionBonus()),
            'energyUsed' => ceil($working * self::CRAWLER_ENERGY * ($level <= 1 ? $level : 1 + ($level - 1) * 2)),
        ];
    }
}
