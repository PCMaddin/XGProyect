<?php

declare(strict_types=1);

namespace App\Services\Game\Formulas;

use App\Services\SettingsService;

/**
 * The rules of expeditions, as in OGame (owiki.de, "Expedition").
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class ExpeditionService
{
    /**
     * The expedition points that count at most, by the points of the best
     * player: [below these points, for resource finds, for ship finds].
     */
    private const POINT_CAPS = [
        [100000, 2400, 1250],
        [1000000, 6000, 3000],
        [5000000, 9000, 4500],
        [25000000, 12000, 6000],
        [50000000, 15000, 7500],
        [75000000, 18000, 9000],
        [100000000, 21000, 10500],
        [PHP_INT_MAX, 25000, 12500],
    ];

    /** expedition points that always count for resource finds */
    private const MIN_RESOURCE_POINTS = 200;

    /** structure points a ship find has at least */
    private const MIN_SHIP_FIND = 10000;

    /** a ship find ends after this many draws without a ship */
    private const SHIP_FIND_MISSES = 3;

    /**
     * The ships an expedition can find, from light to heavy: each ship of
     * the fleet finds these up to one step heavier than itself (there is no
     * battlecruiser; the reaper comes after the destroyer).
     */
    private const FINDABLE_SHIPS = [210, 202, 204, 203, 205, 206, 207, 211, 213, 215];

    /** solar satellites do not fly; all other ships count */
    private const NO_EXPEDITION_SHIPS = [212];

    /**
     * Pirates and aliens: share of each ship type of the expedition (each
     * varies by a tenth), and the ships they bring in addition.
     */
    private const ENEMIES = [
        'pirates' => [
            'normal' => [0.3, [204 => 5]],
            'large' => [0.5, [206 => 3]],
            'huge' => [0.8, [207 => 2]],
        ],
        'aliens' => [
            'normal' => [0.4, [205 => 5]],
            // the original brings 3 battlecruisers, which the game does not have
            'large' => [0.6, [207 => 3]],
            'huge' => [0.9, [213 => 2]],
        ],
    ];

    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * The expedition points that count at most for resource finds.
     */
    public function getMaxExpeditionPoints(int | float $topPlayerPoints): int
    {
        return $this->pointCaps($topPlayerPoints)[1];
    }

    /**
     * The expedition points that count at most for ship finds.
     */
    public function getMaxShipFindPoints(int | float $topPlayerPoints): int
    {
        return $this->pointCaps($topPlayerPoints)[2];
    }

    /**
     * The expedition points that count for a resource find: at most the
     * cap, at least 200.
     */
    public function getResourceFindPoints(int $expeditionPoints, int | float $topPlayerPoints): int
    {
        return max(self::MIN_RESOURCE_POINTS, min($expeditionPoints, $this->getMaxExpeditionPoints($topPlayerPoints)));
    }

    public function calculateExpeditionPoints(int $structuralIntegrity): int
    {
        return intdiv($structuralIntegrity * 5, 1000);
    }

    public function getExpeditionResult(): string
    {
        return $this->pickWeighted($this->getExpeditionResultWeights(), 'nothing');
    }

    public function calculateDarkMatterSourceSize(): string
    {
        return $this->pickWeighted($this->getDarkMatterSourceSizeWeights(), 'small');
    }

    public function getDarkMatterSourceSize(string $discoveryType): int
    {
        if ($discoveryType === 'medium') {
            return mt_rand(500, 700);
        }

        if ($discoveryType === 'large') {
            return mt_rand(1000, 1800);
        }

        return mt_rand(300, 400); // $discoveryType === 'small'
    }

    public function calculateResourceTypeObtained(): string
    {
        return $this->pickWeighted($this->getResourceTypeWeights(), 'metal');
    }

    public function calculateResourceSourceSize(): string
    {
        return $this->pickWeighted($this->getResourceSourceSizeWeights(), 'normal');
    }

    /**
     * The factor of a resource find: an even number, 10-50 for a normal,
     * 50-100 for a large and 100-200 for a huge one.
     */
    public function getResourceSourceSizeMultChances(string $discoveryType): int
    {
        return 2 * match ($discoveryType) {
            'large' => $this->random(25, 50),
            'xl' => $this->random(50, 100),
            default => $this->random(5, 25),
        };
    }

    /**
     * The factor of a ship find: 10-50 for a normal, an even number of
     * 52-100 for a large and of 102-200 for a huge one.
     */
    public function getShipSourceSizeMultChances(string $discoveryType): int
    {
        return match ($discoveryType) {
            'large' => 2 * $this->random(26, 50),
            'xl' => 2 * $this->random(51, 100),
            default => $this->random(10, 50),
        };
    }

    public function getResourceFoundAmount(int $chancesMultiplier, int $expeditionPoints, string $resourceType): int
    {
        $resource = [
            'metal' => 1,
            'crystal' => 2,
            'deuterium' => 3,
        ];

        return (int) floor($chancesMultiplier * $expeditionPoints / $resource[$resourceType]);
    }

    /**
     * The structure points (metal and crystal) of a ship find: the factor
     * times the expedition points (at most the cap for ship finds), at least
     * 10.000.
     */
    public function calculateShipFoundAmount(int $chancesMultiplier, int $expeditionPoints): int
    {
        return max(self::MIN_SHIP_FIND, $chancesMultiplier * $expeditionPoints);
    }

    /**
     * Whether the ship counts for the expedition points: all that fly.
     *
     * Fix: recyclers, colony ships and deathstars did not count.
     */
    public function countsForExpeditionPoints(int $shipId): bool
    {
        return !in_array($shipId, self::NO_EXPEDITION_SHIPS, true);
    }

    /**
     * The ship types the fleet can find: up to one step heavier than its
     * heaviest findable ship. Recyclers, colony ships and deathstars find
     * nothing on their own.
     *
     * @param array<int, int|float> $fleet the expedition's ships by id
     *
     * @return list<int>
     */
    public function getFindableShips(array $fleet): array
    {
        $heaviest = -1;

        foreach (self::FINDABLE_SHIPS as $step => $id) {
            if (($fleet[$id] ?? 0) > 0) {
                $heaviest = $step;
            }
        }

        return $heaviest < 0 ? [] : array_slice(self::FINDABLE_SHIPS, 0, $heaviest + 2);
    }

    /**
     * The ships of a find of $budget structure points (metal and crystal of
     * the ships): again and again one findable type is drawn, each equally
     * likely, and a number of it between 1 and what the rest of the budget
     * buys; it ends when the budget buys no ship any more or after three
     * draws that bought none.
     *
     * Bugfix: the former rule added a share of each type of the fleet times
     * points per ship plus one: a fleet of 100 large cargos found about 600
     * more, and every deathstar, recycler or colony ship in the fleet (no
     * chance defined) brought a free one or stopped the mission with an
     * undefined array key.
     *
     * @param array<int, int|float> $fleet     the expedition's ships by id
     * @param array<int, int>       $structure metal + crystal by ship id
     *
     * @return array<int, int>
     */
    public function getFoundShips(array $fleet, int $budget, array $structure): array
    {
        $findable = array_values(array_filter(
            $this->getFindableShips($fleet),
            fn (int $id): bool => ($structure[$id] ?? 0) > 0
        ));
        $found = [];
        $misses = 0;

        while ($findable !== [] && $misses < self::SHIP_FIND_MISSES) {
            $id = $findable[$this->random(0, count($findable) - 1)];
            $affordable = intdiv($budget, $structure[$id]);

            if ($affordable === 0) {
                $misses++;

                continue;
            }

            $count = $this->random(1, $affordable);
            $found[$id] = ($found[$id] ?? 0) + $count;
            $budget -= $count * $structure[$id];
            $misses = 0;
        }

        ksort($found);

        return $found;
    }

    public function getFleetDeplay(): int
    {
        return $this->pickWeighted($this->getFleetDelayWeights(), 2);
    }

    /** @return array<string, int> */
    public function getExpeditionResultWeights(): array
    {
        return [
            'darkMatter' => $this->settings->getInt('expedition_result_dark_matter_weight'),
            'ships' => $this->settings->getInt('expedition_result_ships_weight'),
            'resources' => $this->settings->getInt('expedition_result_resources_weight'),
            'pirates' => $this->settings->getInt('expedition_result_pirates_weight'),
            'aliens' => $this->settings->getInt('expedition_result_aliens_weight'),
            'delay' => $this->settings->getInt('expedition_result_delay_weight'),
            'early' => $this->settings->getInt('expedition_result_early_weight'),
            'nothing' => $this->settings->getInt('expedition_result_nothing_weight'),
            'merchant' => $this->settings->getInt('expedition_result_merchant_weight'),
            'blackHole' => $this->settings->getInt('expedition_result_black_hole_weight'),
            'item' => $this->settings->getInt('expedition_result_item_weight'),
        ];
    }

    /** @return array<string, int> */
    public function getDarkMatterSourceSizeWeights(): array
    {
        return [
            'small' => $this->settings->getInt('expedition_dark_matter_source_small_weight'),
            'medium' => $this->settings->getInt('expedition_dark_matter_source_medium_weight'),
            'large' => $this->settings->getInt('expedition_dark_matter_source_large_weight'),
        ];
    }

    /** @return array<string, int> */
    public function getResourceTypeWeights(): array
    {
        return [
            'metal' => $this->settings->getInt('expedition_resource_type_metal_weight'),
            'crystal' => $this->settings->getInt('expedition_resource_type_crystal_weight'),
            'deuterium' => $this->settings->getInt('expedition_resource_type_deuterium_weight'),
        ];
    }

    /** @return array<string, int> */
    public function getResourceSourceSizeWeights(): array
    {
        return [
            'normal' => $this->settings->getInt('expedition_resource_source_normal_weight'),
            'large' => $this->settings->getInt('expedition_resource_source_large_weight'),
            'xl' => $this->settings->getInt('expedition_resource_source_xl_weight'),
        ];
    }

    /** @return array<int, int> */
    public function getFleetDelayWeights(): array
    {
        return [
            2 => $this->settings->getInt('expedition_fleet_delay_2_weight'),
            3 => $this->settings->getInt('expedition_fleet_delay_3_weight'),
            5 => $this->settings->getInt('expedition_fleet_delay_5_weight'),
        ];
    }

    /**
     * How big the pirate or alien fleet an expedition meets is.
     */
    public function getEnemySize(): string
    {
        return (string) $this->pickWeighted(['normal' => 89, 'large' => 10, 'huge' => 1], 'normal');
    }

    /**
     * The pirate or alien fleet an expedition meets: of each ship type of
     * the expedition a share that depends on the enemy and the size,
     * varying by a tenth (pirates 30/50/80 %, rounded down; aliens
     * 40/60/90 %, rounded up), then the ships they bring in addition.
     *
     * @param array<int, int|float> $ships the expedition's ships by id
     *
     * @return array<int, int>
     */
    public function getEnemyFleet(string $enemy, string $size, array $ships): array
    {
        [$share, $extra] = self::ENEMIES[$enemy][$size] ?? self::ENEMIES['pirates']['normal'];
        $round = $enemy === 'aliens' ? 'ceil' : 'floor';
        $fleet = [];

        foreach ($ships as $id => $count) {
            $enemyCount = $count > 0 ? (int) $round($count * $share * (1 + $this->random(-100, 100) / 1000)) : 0;

            if ($enemyCount > 0) {
                $fleet[$id] = $enemyCount;
            }
        }

        foreach ($extra as $id => $count) {
            $fleet[$id] = ($fleet[$id] ?? 0) + $count;
        }

        return $fleet;
    }

    /**
     * The enemy's technology levels relative to the player's: pirates are
     * three levels behind, aliens three ahead.
     */
    public function getEnemyTechnologyBonus(string $enemy): int
    {
        return $enemy === 'aliens' ? 3 : -3;
    }

    /**
     * A random integer between $min and $max (both included).
     */
    protected function random(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function pointCaps(int | float $topPlayerPoints): array
    {
        foreach (self::POINT_CAPS as $caps) {
            if ($topPlayerPoints < $caps[0]) {
                return $caps;
            }
        }

        return self::POINT_CAPS[count(self::POINT_CAPS) - 1];
    }

    /**
     * @template T of array-key
     *
     * @param array<T, int> $weights
     * @param T $fallback
     *
     * @return T
     */
    private function pickWeighted(array $weights, string | int $fallback): string | int
    {
        $randomNumber = $this->random(1, array_sum($weights));
        $sum = 0;

        foreach ($weights as $result => $weight) {
            $sum += $weight;

            if ($randomNumber <= $sum) {
                return $result;
            }
        }

        return $fallback;
    }
}
