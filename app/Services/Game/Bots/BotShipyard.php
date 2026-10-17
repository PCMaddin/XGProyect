<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\DefensesEnumerator as Defenses;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Core\GameObjects\Defense;
use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Ship;
use App\Services\Game\Formulas\DevelopmentsService;

/**
 * What a bot's shipyard builds: a colony ship when astrophysics allows a
 * colony, defences first after a raid, then a few probes, cargo ships for
 * expeditions, raids and transports, recyclers and a war fleet of light
 * and heavy fighters and cruisers; what the budget does not spend on ships
 * goes into the best defence allowed, up to a cap per metal mine level.
 *
 * Also rates fleets and defences for the bots' raids.
 */
class BotShipyard
{
    /** strongest first; the first one the bot may build wins */
    private const DEFENCES = [
        Defenses::DEFENSE_PLASMA_TURRET,
        Defenses::DEFENSE_GAUSS_CANNON,
        Defenses::DEFENSE_ION_CANNON,
        Defenses::DEFENSE_HEAVY_LASER,
        Defenses::DEFENSE_LIGHT_LASER,
        Defenses::DEFENSE_ROCKET_LAUNCHER,
    ];

    /** defences per metal mine level, so the metal does not all go into rocket launchers */
    private const DEFENCE_CAPS = [
        Defenses::DEFENSE_PLASMA_TURRET => 0.25,
        Defenses::DEFENSE_GAUSS_CANNON => 1,
        Defenses::DEFENSE_ION_CANNON => 1,
        Defenses::DEFENSE_HEAVY_LASER => 2,
        Defenses::DEFENSE_LIGHT_LASER => 8,
        Defenses::DEFENSE_ROCKET_LAUNCHER => 10,
    ];

    /** the share of the production that goes into ships and defences at least */
    private const FLEET_SHARE = 0.3;

    /** a bot acts every 10 to 20 minutes */
    private const TURN_HOURS = 0.25;

    /** missiles do not fight */
    private const NOT_FIGHTING = [Defenses::DEFENSE_ANTI_BALLISTIC_MISSILE, Defenses::DEFENSE_INTERPLANETARY_MISSILE];

    public function __construct(
        private DevelopmentsService $developments,
        private GameObjectRegistry $registry,
    ) {
    }

    /**
     * The next shipyard order, or null. A wanted colony ship comes first,
     * paid from the whole stock (the buildings' reserve does not hold it
     * back, other ships and defences wait for it). A planet raided lately puts all its stock
     * above the reserve into defences. Otherwise ships and then defences
     * share the spare budget.
     *
     * @param array<int, int>          $levels  levels by object id
     * @param array<int, int>          $ships   ships and defences on the planet by id
     * @param array<string, float|int> $stock   metal, crystal, deuterium
     * @param array<string, float|int> $reserve    what the next building costs
     * @param array<string, float|int> $production metal, crystal, deuterium per hour
     *
     * @return array{0: int, 1: int}|null
     */
    public function order(array $levels, array $ships, bool $home, bool $wantsColony, bool $raided, array $stock, array $reserve, int $maxPerOrder, array $production = []): ?array
    {
        // a wanted colony ship is saved for: no other ship or defence meanwhile
        if ($home && $wantsColony && ($ships[Ships::SHIP_COLONY_SHIP] ?? 0) === 0 &&
            $this->developments->isDevelopmentAllowed(Ships::SHIP_COLONY_SHIP, $levels)) {
            return $this->affordable(Ships::SHIP_COLONY_SHIP, $stock) > 0 ? [Ships::SHIP_COLONY_SHIP, 1] : null;
        }

        $defence = $raided ? $this->defenceOrder($levels, $this->aboveReserve($stock, $reserve), $maxPerOrder, $ships, 2) : null;

        if ($defence !== null) {
            return $defence;
        }

        $budget = $this->spareBudget($stock, $reserve, $production);

        foreach ($this->shipTargets($levels, $home) as $ship => $target) {
            $amount = min($target - ($ships[$ship] ?? 0), $this->affordable($ship, $budget), $maxPerOrder);

            if ($amount > 0 && $this->developments->isDevelopmentAllowed($ship, $levels)) {
                return [$ship, $amount];
            }
        }

        return $this->defenceOrder($levels, $budget, $maxPerOrder, $ships);
    }

    /**
     * The best defence the budget pays for and how many, or null. With the
     * defences on the planet given, each kind stops at its cap per metal
     * mine level (twice that after a raid).
     *
     * @param array<int, int>          $levels
     * @param array<string, float|int> $budget metal, crystal, deuterium
     * @param array<int, int>|null     $units  ships and defences on the planet, null: no caps
     *
     * @return array{0: int, 1: int}|null
     */
    public function defenceOrder(array $levels, array $budget, int $maxPerOrder, ?array $units = null, int $capFactor = 1): ?array
    {
        $metal = $levels[Buildings::BUILDING_METAL_MINE] ?? 0;

        foreach (self::DEFENCES as $defence) {
            if (!$this->developments->isDevelopmentAllowed($defence, $levels)) {
                continue;
            }

            $room = $units === null ? PHP_INT_MAX
                : (int) floor(self::DEFENCE_CAPS[$defence] * $metal * $capFactor) - ($units[$defence] ?? 0);
            $amount = min($this->affordable($defence, $budget), $maxPerOrder, $room);

            if ($amount > 0) {
                return [$defence, $amount];
            }
        }

        return null;
    }

    /**
     * What is left for ships and defences: a quarter of the stock above
     * twice the price of the next building, so the economy grows first, but
     * at least the turn's share of the production (about 30 % of it over
     * time), so a bot saving for an expensive building still builds its
     * fleet.
     *
     * @param array<string, float|int> $stock
     * @param array<string, float|int> $reserve
     * @param array<string, float|int> $production per hour
     *
     * @return array<string, float>
     */
    public function spareBudget(array $stock, array $reserve, array $production = []): array
    {
        $budget = [];

        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $stocked = (float) ($stock[$resource] ?? 0);
            $share = min($stocked, max(0.0, (float) ($production[$resource] ?? 0)) * self::FLEET_SHARE * self::TURN_HOURS);
            $budget[$resource] = max(0.0, ($stocked - 2 * (float) ($reserve[$resource] ?? 0)) / 4, $share);
        }

        return $budget;
    }

    /**
     * The stock above the reserve.
     *
     * @param array<string, float|int> $stock
     * @param array<string, float|int> $reserve
     *
     * @return array<string, float>
     */
    private function aboveReserve(array $stock, array $reserve): array
    {
        $budget = [];

        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $budget[$resource] = max(0.0, (float) ($stock[$resource] ?? 0) - (float) ($reserve[$resource] ?? 0));
        }

        return $budget;
    }

    /**
     * How much a fleet or defence fights: attack, shield and a tenth of the
     * hull of every unit. Rough, but enough to tell a safe target.
     *
     * @param array<int, int> $units unit id => amount
     */
    public function combatValue(array $units): float
    {
        $value = 0.0;

        foreach ($units as $unit => $amount) {
            $object = $this->registry->has($unit) ? $this->registry->get($unit) : null;

            if ((!$object instanceof Ship && !$object instanceof Defense) || in_array($unit, self::NOT_FIGHTING, true)) {
                continue;
            }

            $price = $this->developments->developmentPrice($unit, 0, false);
            $hull = (($price['metal'] ?? 0) + ($price['crystal'] ?? 0)) / 10;
            $value += $amount * ($object->getAttack() + $object->getShield() + $hull / 10);
        }

        return $value;
    }

    /**
     * Every unit that fights, by id, with its column.
     *
     * @return array<int, string>
     */
    public function combatUnits(): array
    {
        $units = [];

        foreach ($this->registry->ships()->keys()->merge($this->registry->defenses()->keys()) as $unit) {
            if (!in_array($unit, self::NOT_FIGHTING, true)) {
                $units[$unit] = $this->column($unit);
            }
        }

        return $units;
    }

    /**
     * The ships and defences on a planet row, by id.
     *
     * @param array<string, mixed> $planet
     *
     * @return array<int, int>
     */
    public function shipsOf(array $planet): array
    {
        $ships = [];

        foreach ($this->registry->ships()->keys()->merge($this->registry->defenses()->keys()) as $ship) {
            $amount = $planet[$this->column($ship)] ?? 0;
            $ships[$ship] = is_numeric($amount) ? (int) $amount : 0;
        }

        return $ships;
    }

    public function column(int $unit): string
    {
        return $this->registry->names()[$unit] ?? '';
    }

    /**
     * How many of each ship the planet should have; they grow with the
     * metal mine. Colonies only keep cargo ships to ship their stock home.
     *
     * @param array<int, int> $levels
     *
     * @return array<int, int>
     */
    private function shipTargets(array $levels, bool $home): array
    {
        $metal = $levels[Buildings::BUILDING_METAL_MINE] ?? 0;

        if (!$home) {
            return [Ships::SHIP_SMALL_CARGO_SHIP => min(10, max(1, intdiv($metal, 3)))];
        }

        // the war fleet: cruisers beat rocket launchers and light fighters,
        // recyclers bring the debris of the battles home
        return [
            Ships::SHIP_ESPIONAGE_PROBE => 3,
            Ships::SHIP_SMALL_CARGO_SHIP => min(40, 2 * $metal),
            Ships::SHIP_RECYCLER => min(10, intdiv($metal, 4)),
            Ships::SHIP_LIGHT_FIGHTER => min(60, 3 * $metal),
            Ships::SHIP_HEAVY_FIGHTER => min(40, 2 * $metal),
            Ships::SHIP_CRUISER => min(100, max(0, 5 * ($metal - 12))),
        ];
    }

    /**
     * @param array<string, float|int> $budget
     */
    private function affordable(int $unit, array $budget): int
    {
        $price = $this->developments->developmentPrice($unit, 0, false);
        $amounts = [];

        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            if (($price[$resource] ?? 0) > 0) {
                $amounts[] = (int) floor((float) ($budget[$resource] ?? 0) / $price[$resource]);
            }
        }

        return $amounts === [] ? 0 : max(0, min($amounts));
    }
}
