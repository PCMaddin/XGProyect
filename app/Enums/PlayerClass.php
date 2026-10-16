<?php

declare(strict_types=1);

namespace App\Enums;

use App\Core\Enumerators\ShipsEnumerator as Ships;

/**
 * The player classes of OGame 7 and their bonuses (OGame wiki,
 * "Collector", "General", "Discoverer").
 */
enum PlayerClass: int
{
    case None = 0;
    case Collector = 1;
    case General = 2;
    case Discoverer = 3;

    /** cargo ships: faster and roomier for collectors */
    public const CARGO_SHIPS = [Ships::SHIP_SMALL_CARGO_SHIP, Ships::SHIP_BIG_CARGO_SHIP];

    /** combat ships: faster for generals (not the deathstar) */
    public const COMBAT_SHIPS = [
        Ships::SHIP_LIGHT_FIGHTER, Ships::SHIP_HEAVY_FIGHTER, Ships::SHIP_CRUISER, Ships::SHIP_BATTLESHIP,
        Ships::SHIP_BATTLECRUISER, Ships::SHIP_BOMBER, Ships::SHIP_DESTROYER, Ships::SHIP_REAPER, Ships::SHIP_PATHFINDER,
    ];

    public static function fromInt(mixed $value): self
    {
        return self::tryFrom(is_numeric($value) ? (int) $value : 0) ?? self::None;
    }

    /**
     * The language key of its name.
     */
    public function nameKey(): string
    {
        return 'cc_' . strtolower($this->name);
    }

    /**
     * The ship only this class can build, if any.
     */
    public function classShip(): ?int
    {
        return match ($this) {
            self::Collector => Ships::SHIP_CRAWLER,
            self::General => Ships::SHIP_REAPER,
            self::Discoverer => Ships::SHIP_PATHFINDER,
            self::None => null,
        };
    }

    /**
     * Whether the class may build the ship (crawler, reaper and pathfinder
     * belong to one class each).
     */
    public function canBuild(int $shipId): bool
    {
        foreach (self::cases() as $class) {
            if ($class->classShip() === $shipId) {
                return $class === $this;
            }
        }

        return true;
    }

    /**
     * Speed factor of a ship: collectors fly cargo ships, generals combat
     * ships (not deathstars) and recyclers twice as fast.
     */
    public function speedFactor(int $shipId): float
    {
        return match (true) {
            $this === self::Collector && in_array($shipId, self::CARGO_SHIPS, true) => 2.0,
            $this === self::General && (in_array($shipId, self::COMBAT_SHIPS, true) || $shipId === Ships::SHIP_RECYCLER) => 2.0,
            default => 1.0,
        };
    }

    /**
     * Extra cargo, as share of the base capacity: collectors +25 % on cargo
     * ships, generals +20 % on recyclers and pathfinders.
     */
    public function cargoBonus(int $shipId): float
    {
        return match (true) {
            $this === self::Collector && in_array($shipId, self::CARGO_SHIPS, true) => 0.25,
            $this === self::General && in_array($shipId, [Ships::SHIP_RECYCLER, Ships::SHIP_PATHFINDER], true) => 0.2,
            default => 0.0,
        };
    }

    /** generals use 25 % less deuterium */
    public function consumptionFactor(): float
    {
        return $this === self::General ? 0.75 : 1.0;
    }

    /** generals have 2 more fleet slots */
    public function extraFleetSlots(): int
    {
        return $this === self::General ? 2 : 0;
    }

    /** discoverers have 2 more expedition slots */
    public function extraExpeditions(): int
    {
        return $this === self::Discoverer ? 2 : 0;
    }

    /** generals fight with 2 more levels of weapons, shields and armour */
    public function combatTechBonus(): int
    {
        return $this === self::General ? 2 : 0;
    }

    /** generals' moons have 5 more fields */
    public function moonFieldBonus(): int
    {
        return $this === self::General ? 5 : 0;
    }

    /** discoverers research 25 % faster */
    public function researchTimeFactor(): float
    {
        return $this === self::Discoverer ? 0.75 : 1.0;
    }

    /** discoverers' phalanx reaches 20 % farther */
    public function phalanxRangeFactor(): float
    {
        return $this === self::Discoverer ? 1.2 : 1.0;
    }

    /** discoverers colonize 10 % larger planets (21 % more fields) */
    public function planetSizeFactor(): float
    {
        return $this === self::Discoverer ? 1.1 : 1.0;
    }

    /** discoverers plunder 75 % from inactive players (others 50 %) */
    public function inactiveLootShare(): float
    {
        return $this === self::Discoverer ? 0.75 : 0.5;
    }

    /** collectors: +25 % mine production */
    public function mineProductionBonus(): float
    {
        return $this === self::Collector ? 0.25 : 0.0;
    }

    /** collectors: +10 % energy production */
    public function energyProductionBonus(): float
    {
        return $this === self::Collector ? 0.1 : 0.0;
    }

    /** discoverers: resources and ships found on expeditions × 1,5 */
    public function expeditionFindFactor(): float
    {
        return $this === self::Discoverer ? 1.5 : 1.0;
    }

    /** discoverers meet pirates and aliens half as often */
    public function expeditionEnemyFactor(): float
    {
        return $this === self::Discoverer ? 0.5 : 1.0;
    }
}
