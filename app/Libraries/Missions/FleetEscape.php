<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Core\GameObjects\GameObjectRegistry;

/**
 * The fleet escape (tactical retreat) of the original: a defender's fleet
 * flees from an attacker at least 5 times as strong (3 times with an
 * admiral). Strength is the cost of the ships in the battle, civil ships
 * count a quarter, defenses not at all. Death stars, probes, solar
 * satellites and crawlers stay.
 */
final class FleetEscape
{
    public const RATIO = 5;
    public const ADMIRAL_RATIO = 3;

    /** the fuel is 1.5 times a flight to the next position */
    public const FUEL_FACTOR = 1.5;

    private const CIVIL = [
        Ships::SHIP_SMALL_CARGO_SHIP, Ships::SHIP_BIG_CARGO_SHIP, Ships::SHIP_COLONY_SHIP, Ships::SHIP_RECYCLER,
        Ships::SHIP_ESPIONAGE_PROBE, Ships::SHIP_SOLAR_SATELLITE, Ships::SHIP_CRAWLER,
    ];

    private const STAYING = [Ships::SHIP_DEATHSTAR, Ships::SHIP_ESPIONAGE_PROBE, Ships::SHIP_SOLAR_SATELLITE, Ships::SHIP_CRAWLER];

    public function __construct(private GameObjectRegistry $registry)
    {
    }

    /**
     * The strength of ships: their cost, civil ships a quarter.
     *
     * @param array<int, int|float> $ships
     */
    public function strength(array $ships): float
    {
        $strength = 0.0;

        foreach ($ships as $id => $count) {
            if (!$this->registry->has($id) || $id >= 400) {
                continue;
            }

            $price = $this->registry->get($id)->getPrice();
            $units = ($price->getMetal() + $price->getCrystal() + $price->getDeuterium()) * $count;
            $strength += in_array($id, self::CIVIL, true) ? floor($units / 4) : $units;
        }

        return $strength;
    }

    /**
     * The ships that can flee.
     *
     * @param array<int, int|float> $ships
     *
     * @return array<int, int>
     */
    public function fleeing(array $ships): array
    {
        $fleeing = [];

        foreach ($ships as $id => $count) {
            if ($id < 400 && $count > 0 && !in_array($id, self::STAYING, true)) {
                $fleeing[$id] = (int) $count;
            }
        }

        return $fleeing;
    }

    public function overwhelms(float $attackers, float $defenders, bool $admiral): bool
    {
        return $defenders > 0 && $attackers >= $defenders * ($admiral ? self::ADMIRAL_RATIO : self::RATIO);
    }
}
