<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\DefensesEnumerator as Defenses;
use App\Core\Enumerators\MissionsEnumerator as Missions;
use App\Core\Enumerators\PlanetTypesEnumerator as PlanetTypes;
use App\Core\GameObjects\Defense;
use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\Game\Formulas\FormulasService;
use App\Services\Game\MissileService;
use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Support\Facades\DB;

/**
 * Interplanetary missiles. Once the home planet has its missile silo (see
 * {@see BotStrategy}), its shipyard keeps a few missiles, paid from the
 * spare budget. They are fired like from the galaxy page (silo level 4,
 * impulse drive, range, same galaxy) at the defences of a target the bot
 * or an ally spied on lately, to clear the way for a raid: as many as it
 * takes to destroy the defences, counting the anti-ballistic missiles that
 * intercept them, and only if they destroy at least their own price.
 */
class BotMissiles
{
    use ReadsMixedValues;

    /** missiles the home planet keeps */
    private const MISSILES = 8;

    /** the silo level a launch needs, as on the galaxy page */
    private const SILO_TO_FIRE = 4;

    /** a report older than this is not acted on */
    private const REPORT_VALID = 3 * 3600;

    /** silo space: an interplanetary missile takes two slots of ten per level */
    private const SLOTS_PER_LEVEL = 10;

    public function __construct(
        private BotFleetPlanner $planner,
        private DevelopmentsService $developments,
        private FormulasService $formulas,
        private GameObjectRegistry $registry,
        private MissileService $missiles,
    ) {
    }

    /**
     * Missiles for the silo, or null.
     *
     * @param array<int, int>          $levels
     * @param array<string, mixed>     $planet the planet row with its defences
     * @param array<string, float|int> $budget metal, crystal, deuterium
     *
     * @return array{0: int, 1: int}|null
     */
    public function order(array $levels, array $planet, array $budget, int $maxPerOrder): ?array
    {
        $missile = Defenses::DEFENSE_INTERPLANETARY_MISSILE;

        if (!$this->developments->isDevelopmentAllowed($missile, $levels)) {
            return null;
        }

        $stock = self::asInt($planet['defense_interplanetary_missile'] ?? 0);
        $space = ($levels[Buildings::BUILDING_MISSILE_SILO] ?? 0) * self::SLOTS_PER_LEVEL
            - self::asInt($planet['defense_anti-ballistic_missile'] ?? 0) - 2 * $stock;
        $amount = min(self::MISSILES - $stock, intdiv(max(0, $space), 2), $this->affordable($missile, $budget), $maxPerOrder);

        return $amount > 0 ? [$missile, $amount] : null;
    }

    /**
     * Whether a planet the bot may fire at lies within the range of its
     * home planet's missiles (with at least impulse drive 1).
     *
     * @param array<string, mixed> $user
     */
    public function targetInReach(array $user): bool
    {
        $home = DB::table('planets')->where('planet_id', self::asInt($user['home_planet_id'] ?? 0))->first(['planet_galaxy', 'planet_system']);

        if ($home === null) {
            return false;
        }

        $system = self::asInt($home->planet_system);
        $range = $this->formulas->missileRange(max(1, self::asInt($user['research_impulse_drive'] ?? 0)));

        return $this->planner->targetQuery($user)
            ->where('p.planet_galaxy', self::asInt($home->planet_galaxy))
            ->whereBetween('p.planet_system', [$system - $range + 1, $system + $range - 1])
            ->exists();
    }

    /**
     * Fire at the first target in range whose defences are worth it.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet the home planet's row with buildings and defences
     * @param list<int>            $allies
     */
    public function fire(array $user, array $planet, array $allies, int $now): bool
    {
        $stock = self::asInt($planet['defense_interplanetary_missile'] ?? 0);
        $impulse = self::asInt($user['research_impulse_drive'] ?? 0);

        if ($stock === 0 || $impulse === 0 || self::asInt($planet['building_missile_silo'] ?? 0) < self::SILO_TO_FIRE) {
            return false;
        }

        // the missile mission's damage: attack with weapons technology
        $missile = $this->registry->get(Defenses::DEFENSE_INTERPLANETARY_MISSILE);
        $damage = ($missile instanceof Defense ? $missile->getAttack() : 0) * (1 + self::asInt($user['research_weapons_technology'] ?? 0) / 10);

        if ($damage <= 0) {
            return false;
        }

        foreach ($this->targets($user, $planet, $allies, $this->formulas->missileRange($impulse), $now) as $target) {
            [$hull, $price] = $this->defences($target);
            $intercepted = self::asInt($target['defense_anti-ballistic_missile'] ?? 0);
            $amount = min($stock, $intercepted + (int) ceil($hull / $damage));
            $destroyed = $hull > 0 ? min(1.0, max(0, $amount - $intercepted) * $damage / $hull) * $price : 0.0;

            if ($amount <= $intercepted || $destroyed < $amount * $this->price(Defenses::DEFENSE_INTERPLANETARY_MISSILE) ||
                !$this->planner->targetAllowed($user, $target, $now)) {
                continue;
            }

            $this->missiles->launch(
                self::asInt($user['id'] ?? 0),
                self::asInt($planet['planet_id'] ?? 0),
                ['galaxy' => self::asInt($planet['planet_galaxy'] ?? 0), 'system' => self::asInt($planet['planet_system'] ?? 0), 'planet' => self::asInt($planet['planet_planet'] ?? 0)],
                ['galaxy' => self::asInt($target['planet_galaxy'] ?? 0), 'system' => self::asInt($target['planet_system'] ?? 0), 'planet' => self::asInt($target['planet_planet'] ?? 0)],
                $amount,
                0,
                self::asInt($target['planet_user_id'] ?? 0),
                $now
            );

            return true;
        }

        return false;
    }

    /**
     * Defended planets in range that the bot or an ally spied on lately and
     * no missile of the bot flies to, the nearest first.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param list<int>            $allies
     *
     * @return list<array<string, mixed>>
     */
    private function targets(array $user, array $planet, array $allies, int $range, int $now): array
    {
        $userId = self::asInt($user['id'] ?? 0);
        $system = self::asInt($planet['planet_system'] ?? 0);
        $spies = array_merge([$userId], $allies);

        return array_values(array_map(
            static fn (object $row): array => get_object_vars($row),
            $this->planner->targetQuery($user)
                ->join('defenses AS d', 'd.defense_planet_id', '=', 'p.planet_id')
                ->join('research AS r', 'r.research_user_id', '=', 'p.planet_user_id')
                ->addSelect('d.*', 'r.research_shielding_technology')
                ->where('p.planet_galaxy', self::asInt($planet['planet_galaxy'] ?? 0))
                ->whereBetween('p.planet_system', [$system - $range + 1, $system + $range - 1])
                ->whereExists(fn ($spied) => $spied->from('espionage_log AS e')
                    ->whereColumn('e.planet_id', 'p.planet_id')
                    ->whereIn('e.user_id', $spies)
                    ->where('e.spied_at', '>', $now - self::REPORT_VALID))
                ->whereNotExists(fn ($flying) => $flying->from('fleets AS f')
                    ->where('f.fleet_owner', $userId)
                    ->where('f.fleet_mission', Missions::MISSILE)
                    ->whereColumn('f.fleet_end_galaxy', 'p.planet_galaxy')
                    ->whereColumn('f.fleet_end_system', 'p.planet_system')
                    ->whereColumn('f.fleet_end_planet', 'p.planet_planet'))
                ->where('p.planet_type', PlanetTypes::PLANET)
                ->orderByRaw('ABS(' . DB::getQueryGrammar()->wrap('p.planet_system') . ' - ?)', [$system])
                ->limit(10)
                ->get()
                ->all()
        ));
    }

    /**
     * What the missiles must break through (the hull the missile mission
     * counts, with the target's shielding) and what the defences cost.
     *
     * @param array<string, mixed> $target
     *
     * @return array{0: float, 1: float}
     */
    private function defences(array $target): array
    {
        $shielding = 1 + self::asInt($target['research_shielding_technology'] ?? 0) / 10;
        $hull = 0.0;
        $price = 0.0;

        foreach ($this->registry->defenses()->keys() as $defenceId) {
            if (in_array($defenceId, [Defenses::DEFENSE_ANTI_BALLISTIC_MISSILE, Defenses::DEFENSE_INTERPLANETARY_MISSILE], true)) {
                continue;
            }

            $amount = self::asInt($target[$this->registry->names()[$defenceId] ?? ''] ?? 0);
            $cost = $this->developments->developmentPrice($defenceId, 0, false);
            $hull += $amount * (($cost['metal'] ?? 0) + ($cost['crystal'] ?? 0)) / 10 * $shielding;
            $price += $amount * $this->price($defenceId);
        }

        return [$hull, $price];
    }

    private function price(int $unit): float
    {
        return (float) array_sum($this->developments->developmentPrice($unit, 0, false));
    }

    /**
     * How many of the unit the budget pays for.
     *
     * @param array<string, float|int> $budget
     */
    public function affordable(int $unit, array $budget): int
    {
        $amounts = [];

        foreach ($this->developments->developmentPrice($unit, 0, false) as $resource => $amount) {
            if ($amount > 0) {
                $amounts[] = (int) floor((float) ($budget[$resource] ?? 0) / $amount);
            }
        }

        return $amounts === [] ? 0 : max(0, min($amounts));
    }
}
