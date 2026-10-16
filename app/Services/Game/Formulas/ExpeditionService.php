<?php

declare(strict_types=1);

namespace App\Services\Game\Formulas;

use App\Services\SettingsService;

class ExpeditionService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Also applies for resources
     */
    public function getMaxExpeditionPoints(int | float $topPlayerPoints): int
    {
        if ($topPlayerPoints < 100000) {
            return 2500;
        }

        if ($topPlayerPoints < 1000000) {
            return 6000;
        }

        if ($topPlayerPoints < 5000000) {
            return 9000;
        }

        if ($topPlayerPoints < 25000000) {
            return 12000;
        }

        if ($topPlayerPoints < 50000000) {
            return 15000;
        }

        if ($topPlayerPoints < 75000000) {
            return 18000;
        }

        if ($topPlayerPoints < 100000000) {
            return 21000;
        }

        return 25000;
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

    public function getResourceSourceSizeMultChances(string $discoveryType): int
    {
        if ($discoveryType === 'large') {
            return mt_rand(50, 100);
        }

        if ($discoveryType === 'xl') {
            return mt_rand(100, 200);
        }

        return mt_rand(10, 50); // $discoveryType === 'normal'
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

    public function calculateShipFoundAmount(int $chancesMultiplier, int $expeditionPoints): int
    {
        return (int) floor($chancesMultiplier * $expeditionPoints / 2);
    }

    /**
     * Only these ships are computed for the expeditions points
     *
     * @return array<Int, Int>
     */
    public function getPossibleShips(): array
    {
        return [
            202, // ship_small_cargo_ship
            203, // ship_big_cargo_ship
            204, // ship_light_fighter
            205, // ship_heavy_fighter
            206, // ship_cruiser
            207, // ship_battleship
            210, // ship_espionage_probe
            211, // ship_bomber
            213, // ship_destroyer
            215, // ship_reaper
        ];
    }

    /**
     * Only these ships are obtainable on an expedition
     *
     * @return array<Int, Float>
     */
    public function getShipsObtainableChances(): array
    {
        return [
            202 => 0.1, // ship_small_cargo_ship
            203 => 0.1, // ship_big_cargo_ship
            204 => 0.1, // ship_light_fighter
            205 => 0.5, // ship_heavy_fighter
            206 => 0.25, // ship_cruiser
            207 => 0.125, // ship_battleship
            210 => 0.1, // ship_espionage_probe
            211 => 0.0625, // ship_bomber
            213 => 0.0625, // ship_destroyer
            215 => 0.0625, // ship_reaper
        ];
    }

    /**
     * The ships an expedition finds for $budget structure points (metal and
     * crystal of the ships): only the obtainable types, none bigger than the
     * biggest obtainable ship of the fleet, the budget split between them by
     * their chances. Empty when the fleet has no obtainable ship or the
     * budget buys none.
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
        $chances = $this->getShipsObtainableChances();
        $largest = 0;

        foreach ($fleet as $id => $count) {
            if ($count > 0 && isset($chances[$id])) {
                $largest = max($largest, $structure[$id] ?? 0);
            }
        }

        $candidates = array_filter(
            $chances,
            fn (int $id): bool => ($structure[$id] ?? 0) > 0 && $structure[$id] <= $largest,
            ARRAY_FILTER_USE_KEY
        );
        $total = array_sum($candidates);
        $found = [];

        foreach ($candidates as $id => $chance) {
            $count = (int) floor($budget * $chance / $total / $structure[$id]);

            if ($count > 0) {
                $found[$id] = $count;
            }
        }

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
     * The pirate or alien fleet an expedition meets: the expedition's own
     * ship types, each in a share of its number (rounded up) that depends on
     * the enemy and the size: pirates 30/50/80 %, aliens 40/60/90 %.
     *
     * @param array<int, int|float> $ships the expedition's ships by id
     *
     * @return array<int, int>
     */
    public function getEnemyFleet(string $enemy, string $size, array $ships): array
    {
        $shares = [
            'pirates' => ['normal' => 0.3, 'large' => 0.5, 'huge' => 0.8],
            'aliens' => ['normal' => 0.4, 'large' => 0.6, 'huge' => 0.9],
        ];
        $share = $shares[$enemy][$size] ?? 0.3;
        $fleet = [];

        foreach ($ships as $id => $count) {
            if ($count > 0) {
                $fleet[$id] = (int) ceil($count * $share);
            }
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
     * @template T of array-key
     *
     * @param array<T, int> $weights
     * @param T $fallback
     *
     * @return T
     */
    private function pickWeighted(array $weights, string | int $fallback): string | int
    {
        $randomNumber = mt_rand(1, array_sum($weights));
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
