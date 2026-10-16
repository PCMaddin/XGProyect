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
 * expeditions, raids and transports and light fighters; what the budget
 * does not spend on ships goes into the best defence allowed.
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

    /** missiles do not fight */
    private const NOT_FIGHTING = [Defenses::DEFENSE_ANTI_BALLISTIC_MISSILE, Defenses::DEFENSE_INTERPLANETARY_MISSILE];

    public function __construct(
        private DevelopmentsService $developments,
        private GameObjectRegistry $registry,
    ) {
    }

    /**
     * The next shipyard order, or null. A wanted colony ship comes first,
     * paid from the whole stock. A planet raided lately puts all its stock
     * above the reserve into defences. Otherwise ships and then defences
     * share the spare budget.
     *
     * @param array<int, int>          $levels  levels by object id
     * @param array<int, int>          $ships   ships on the planet by id
     * @param array<string, float|int> $stock   metal, crystal, deuterium
     * @param array<string, float|int> $reserve what the next building costs
     *
     * @return array{0: int, 1: int}|null
     */
    public function order(array $levels, array $ships, bool $home, bool $wantsColony, bool $raided, array $stock, array $reserve, int $maxPerOrder): ?array
    {
        if ($home && $wantsColony && ($ships[Ships::SHIP_COLONY_SHIP] ?? 0) === 0 &&
            $this->developments->isDevelopmentAllowed(Ships::SHIP_COLONY_SHIP, $levels) &&
            $this->affordable(Ships::SHIP_COLONY_SHIP, $stock) > 0) {
            return [Ships::SHIP_COLONY_SHIP, 1];
        }

        $defence = $raided ? $this->defenceOrder($levels, $this->aboveReserve($stock, $reserve), $maxPerOrder) : null;

        if ($defence !== null) {
            return $defence;
        }

        $budget = $this->spareBudget($stock, $reserve);

        foreach ($this->shipTargets($levels, $home) as $ship => $target) {
            $amount = min($target - ($ships[$ship] ?? 0), $this->affordable($ship, $budget), $maxPerOrder);

            if ($amount > 0 && $this->developments->isDevelopmentAllowed($ship, $levels)) {
                return [$ship, $amount];
            }
        }

        return $this->defenceOrder($levels, $budget, $maxPerOrder);
    }

    /**
     * The best defence the budget pays for and how many, or null.
     *
     * @param array<int, int>          $levels
     * @param array<string, float|int> $budget metal, crystal, deuterium
     *
     * @return array{0: int, 1: int}|null
     */
    public function defenceOrder(array $levels, array $budget, int $maxPerOrder): ?array
    {
        foreach (self::DEFENCES as $defence) {
            if (!$this->developments->isDevelopmentAllowed($defence, $levels)) {
                continue;
            }

            $amount = min($this->affordable($defence, $budget), $maxPerOrder);

            if ($amount > 0) {
                return [$defence, $amount];
            }
        }

        return null;
    }

    /**
     * What is left for ships and defences: a quarter of the stock above
     * twice the price of the next building, so the economy grows first.
     *
     * @param array<string, float|int> $stock
     * @param array<string, float|int> $reserve
     *
     * @return array<string, float>
     */
    public function spareBudget(array $stock, array $reserve): array
    {
        $budget = [];

        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $budget[$resource] = max(0.0, ((float) ($stock[$resource] ?? 0) - 2 * (float) ($reserve[$resource] ?? 0)) / 4);
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
     * The ships on a planet row, by id.
     *
     * @param array<string, mixed> $planet
     *
     * @return array<int, int>
     */
    public function shipsOf(array $planet): array
    {
        $ships = [];

        foreach ($this->registry->ships()->keys() as $ship) {
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

        return [
            Ships::SHIP_ESPIONAGE_PROBE => 3,
            Ships::SHIP_SMALL_CARGO_SHIP => min(40, 2 * $metal),
            Ships::SHIP_LIGHT_FIGHTER => min(50, 2 * $metal),
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
