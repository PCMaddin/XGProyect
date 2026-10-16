<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Core\Concerns\PreparesSql;
use App\Core\Enumerators\MissionsEnumerator as Missions;
use App\Core\Enumerators\PlanetTypesEnumerator as PlanetTypes;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Libraries\NoobsProtectionLib;
use App\Services\Game\Formulas\FleetsService;
use App\Services\SettingsService;
use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Support\Facades\DB;

/**
 * Where a bot's ships fly. At most one fleet per planet and turn:
 *
 * - the home planet founds colonies (as many as astrophysics allows, on a
 *   free position close to home), raids targets it has spied on and found
 *   weak, spies on the nearest other players and sends spare cargo ships
 *   on expeditions;
 * - colonies ship what they do not need to the home planet.
 *
 * Bots attack only other bots unless the admin allows them to attack
 * players (option bots_attack_players); admins, players on vacation and
 * those under noob protection are left alone. A raid follows a spy report:
 * when the probe has arrived, the bot judges the target by what the report
 * shows (resources, ships, defences) and attacks only an undefended planet
 * or one its warships outgun three times.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class BotFleetPlanner
{
    use PreparesSql;
    use ReadsMixedValues;

    /** systems around the home planet a bot looks at */
    private const RANGE = 10;

    /** a target is spied on again after this long */
    private const SPY_AGAIN = 6 * 3600;

    /** a report older than this is not acted on */
    private const REPORT_VALID = 3 * 3600;

    /** a raid must bring at least this much */
    private const MIN_LOOT = 5000;

    /** the share of the stock a raid plunders */
    private const LOOT_SHARE = 0.5;

    /** expeditions last one hour */
    private const EXPEDITION_HOURS = 1;

    /** colony positions, the larger middle ones first */
    private const COLONY_POSITIONS = [8, 7, 9, 6, 10, 5, 11, 4, 12];

    private const CARGO_SHIPS = [Ships::SHIP_BIG_CARGO_SHIP, Ships::SHIP_SMALL_CARGO_SHIP];

    private const WARSHIPS = [
        Ships::SHIP_LIGHT_FIGHTER, Ships::SHIP_HEAVY_FIGHTER, Ships::SHIP_CRUISER, Ships::SHIP_BATTLESHIP,
        Ships::SHIP_BATTLECRUISER, Ships::SHIP_BOMBER, Ships::SHIP_DESTROYER,
    ];

    public function __construct(
        private BotFleetService $sender,
        private BotShipyard $shipyard,
        private FleetsService $fleets,
        private SettingsService $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $user    the bot's row with research, premium and class
     * @param array<string, mixed> $planet  the planet's row with its ships and defences
     * @param array<string, float> $reserve what the planet keeps for its next building
     */
    public function act(array $user, array $planet, array $reserve, int $now): void
    {
        if (self::asInt($planet['planet_id'] ?? 0) !== self::asInt($user['home_planet_id'] ?? 0)) {
            $this->shipHome($user, $planet, $reserve, $now);

            return;
        }

        $this->colonize($user, $planet, $now) ||
            $this->raid($user, $planet, $now) ||
            $this->spy($user, $planet, $now) ||
            $this->expedition($user, $planet, $now);
    }

    /**
     * Whether the bot may found another colony: astrophysics allows more
     * than it has and is flying to.
     *
     * @param array<string, mixed> $user
     */
    public function wantsColony(array $user): bool
    {
        $userId = self::asInt($user['id'] ?? 0);
        $colonies = DB::table('planets')
            ->where('planet_user_id', $userId)
            ->where('planet_type', PlanetTypes::PLANET)
            ->where('planet_destroyed', 0)
            ->count() - 1;
        $flying = DB::table('fleets')
            ->where('fleet_owner', $userId)
            ->where('fleet_mission', Missions::COLONIZE)
            ->where('fleet_mess', 0)
            ->count();

        return $colonies + $flying < $this->fleets->getMaxColonies(self::asInt($user['research_astrophysics'] ?? 0));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     */
    private function colonize(array $user, array $planet, int $now): bool
    {
        if (self::asInt($planet['ship_colony_ship'] ?? 0) === 0 || !$this->wantsColony($user)) {
            return false;
        }

        $target = $this->freePosition($planet);

        return $target !== null && $this->sender->send($user, $planet, [Ships::SHIP_COLONY_SHIP => 1], $target, Missions::COLONIZE, [], 0, $now);
    }

    /**
     * The free position nearest to the planet, not already targeted by a
     * colony ship.
     *
     * @param array<string, mixed> $planet
     *
     * @return array{galaxy: int, system: int, planet: int, type: int}|null
     */
    private function freePosition(array $planet): ?array
    {
        $galaxy = self::asInt($planet['planet_galaxy'] ?? 0);
        $home = self::asInt($planet['planet_system'] ?? 0);

        foreach ($this->systemsAround($home) as $system) {
            $taken = DB::table('planets')
                ->where('planet_galaxy', $galaxy)
                ->where('planet_system', $system)
                ->where('planet_type', PlanetTypes::PLANET)
                ->pluck('planet_planet')
                ->merge(DB::table('fleets')
                    ->where('fleet_mission', Missions::COLONIZE)
                    ->where('fleet_end_galaxy', $galaxy)
                    ->where('fleet_end_system', $system)
                    ->pluck('fleet_end_planet'))
                ->map(static fn (mixed $position): int => self::asInt($position))
                ->all();

            foreach (self::COLONY_POSITIONS as $position) {
                if (!in_array($position, $taken, true)) {
                    return ['galaxy' => $galaxy, 'system' => $system, 'planet' => $position, 'type' => PlanetTypes::PLANET];
                }
            }
        }

        return null;
    }

    /**
     * @return list<int> the home system first, then outwards
     */
    private function systemsAround(int $home): array
    {
        $systems = [$home];

        for ($distance = 1; $distance <= self::RANGE; $distance++) {
            foreach ([$home - $distance, $home + $distance] as $system) {
                if ($system >= 1 && $system <= MAX_SYSTEM_IN_GALAXY) {
                    $systems[] = $system;
                }
            }
        }

        return $systems;
    }

    /**
     * Raid the best target whose spy report has arrived.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     */
    private function raid(array $user, array $planet, int $now): bool
    {
        $cargoShips = $this->available($planet, self::CARGO_SHIPS);

        if ($cargoShips === []) {
            return false;
        }

        foreach ($this->reportedTargets($user, $now) as $target) {
            $warships = $this->available($planet, self::WARSHIPS);
            $defended = $this->shipyard->combatValue($this->forces($target)) > 0;

            if ($defended && $this->shipyard->combatValue($warships) < 3 * $this->shipyard->combatValue($this->forces($target))) {
                continue;
            }

            $loot = self::LOOT_SHARE * (self::asFloat($target['planet_metal'] ?? 0) + self::asFloat($target['planet_crystal'] ?? 0) + self::asFloat($target['planet_deuterium'] ?? 0));

            if ($loot < self::MIN_LOOT || !$this->targetAllowed($user, $target, $now)) {
                continue;
            }

            $fleet = $this->cargoFor($cargoShips, $loot, $user) + ($defended ? $warships : []);

            return $this->sender->send($user, $planet, $fleet, $this->coordinates($target), Missions::ATTACK, [], 0, $now);
        }

        return false;
    }

    /**
     * Spy on the nearest target not spied on lately.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     */
    private function spy(array $user, array $planet, int $now): bool
    {
        if (self::asInt($planet['ship_espionage_probe'] ?? 0) === 0) {
            return false;
        }

        foreach ($this->candidates($user, $planet) as $target) {
            $spied = DB::table('espionage_log')
                ->where('user_id', self::asInt($user['id'] ?? 0))
                ->where('planet_id', self::asInt($target['planet_id'] ?? 0))
                ->where('spied_at', '>', $now - self::SPY_AGAIN)
                ->exists();

            if (!$spied && $this->targetAllowed($user, $target, $now)) {
                return $this->sender->send($user, $planet, [Ships::SHIP_ESPIONAGE_PROBE => 1], $this->coordinates($target), Missions::SPY, [], 0, $now);
            }
        }

        return false;
    }

    /**
     * Half the idle cargo ships and a probe explore deep space.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     */
    private function expedition(array $user, array $planet, int $now): bool
    {
        $cargo = self::asInt($planet['ship_small_cargo_ship'] ?? 0);

        if (self::asInt($user['research_astrophysics'] ?? 0) === 0 || $cargo < 2) {
            return false;
        }

        $ships = [Ships::SHIP_SMALL_CARGO_SHIP => intdiv($cargo, 2)];

        if (self::asInt($planet['ship_espionage_probe'] ?? 0) > 1) {
            $ships[Ships::SHIP_ESPIONAGE_PROBE] = 1;
        }

        $target = [
            'galaxy' => self::asInt($planet['planet_galaxy'] ?? 0),
            'system' => self::asInt($planet['planet_system'] ?? 0),
            'planet' => MAX_PLANET_IN_SYSTEM + 1,
            'type' => PlanetTypes::PLANET,
        ];

        return $this->sender->send($user, $planet, $ships, $target, Missions::EXPEDITION, [], self::EXPEDITION_HOURS, $now);
    }

    /**
     * A colony ships its stock above the reserve to the home planet.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param array<string, float> $reserve
     */
    private function shipHome(array $user, array $planet, array $reserve, int $now): void
    {
        $ships = $this->available($planet, self::CARGO_SHIPS);
        $home = DB::table('planets')->where('planet_id', self::asInt($user['home_planet_id'] ?? 0))->first();

        if ($ships === [] || $home === null) {
            return;
        }

        $space = $this->sender->capacity($ships, self::asInt($user['research_hyperspace_technology'] ?? 0), $user);
        $cargo = [];

        // metal, crystal, then deuterium, keeping enough deuterium for the flight
        foreach (['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 1000.0] as $resource => $keep) {
            $surplus = (int) max(0, self::asFloat($planet['planet_' . $resource] ?? 0) - ($reserve[$resource] ?? 0) - $keep);
            $cargo[$resource] = min($surplus, $space);
            $space -= $cargo[$resource];
        }

        if (array_sum($cargo) < self::MIN_LOOT) {
            return;
        }

        $target = [
            'galaxy' => self::asInt($home->planet_galaxy),
            'system' => self::asInt($home->planet_system),
            'planet' => self::asInt($home->planet_planet),
            'type' => PlanetTypes::PLANET,
        ];

        $this->sender->send($user, $planet, $ships, $target, Missions::TRANSPORT, $cargo, 0, $now);
    }

    /**
     * Planets of other players near the home planet, nearest first.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(array $user, array $planet): array
    {
        $system = self::asInt($planet['planet_system'] ?? 0);

        return array_values(array_map(
            static fn (object $row): array => get_object_vars($row),
            $this->targetQuery($user)
                ->where('p.planet_galaxy', self::asInt($planet['planet_galaxy'] ?? 0))
                ->whereBetween('p.planet_system', [$system - self::RANGE, $system + self::RANGE])
                ->orderByRaw('ABS(' . $this->column('p.planet_system') . ' - ?)', [$system])
                ->orderBy('p.planet_planet')
                ->limit(30)
                ->get()
                ->all()
        ));
    }

    /**
     * Spied-on planets whose report has arrived and that no own fleet is
     * flying to, the richest first.
     *
     * @param array<string, mixed> $user
     *
     * @return list<array<string, mixed>>
     */
    private function reportedTargets(array $user, int $now): array
    {
        $userId = self::asInt($user['id'] ?? 0);

        return array_values(array_map(
            static fn (object $row): array => get_object_vars($row),
            $this->targetQuery($user)
                ->join('espionage_log AS e', 'e.planet_id', '=', 'p.planet_id')
                ->join('defenses AS d', 'd.defense_planet_id', '=', 'p.planet_id')
                ->join('ships AS s', 's.ship_planet_id', '=', 'p.planet_id')
                ->addSelect('d.*', 's.*')
                ->where('e.user_id', $userId)
                ->where('e.spied_at', '>', $now - self::REPORT_VALID)
                ->whereNotExists(fn ($fleets) => $fleets->from('fleets AS f')
                    ->where('f.fleet_owner', $userId)
                    ->whereColumn('f.fleet_end_galaxy', 'p.planet_galaxy')
                    ->whereColumn('f.fleet_end_system', 'p.planet_system')
                    ->whereColumn('f.fleet_end_planet', 'p.planet_planet')
                    ->where(fn ($flying) => $flying->where('f.fleet_mission', Missions::ATTACK)
                        ->orWhere(fn ($spy) => $spy->where('f.fleet_mission', Missions::SPY)->where('f.fleet_mess', 0))))
                ->orderByRaw($this->column('p.planet_metal') . ' + ' . $this->column('p.planet_crystal') . ' + ' . $this->column('p.planet_deuterium') . ' DESC')
                ->limit(5)
                ->get()
                ->all()
        ));
    }

    /**
     * Planets of others a bot may fly against: no admins, no one on
     * vacation, and players only if the admin allows it.
     *
     * @param array<string, mixed> $user
     */
    private function targetQuery(array $user): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('planets AS p')
            ->join('users AS u', 'u.id', '=', 'p.planet_user_id')
            ->join('preferences AS pr', 'pr.preference_user_id', '=', 'u.id')
            ->select('p.*', 'u.user_is_bot', 'u.onlinetime')
            ->where('p.planet_type', PlanetTypes::PLANET)
            ->where('p.planet_destroyed', 0)
            ->where('p.planet_user_id', '<>', self::asInt($user['id'] ?? 0))
            ->where('u.authlevel', 0)
            ->where(fn ($vacation) => $vacation->whereNull('pr.preference_vacation_mode')->orWhere('pr.preference_vacation_mode', 0));

        if (!$this->settings->getBool('bots_attack_players')) {
            $query->where('u.user_is_bot', true);
        }

        return $query;
    }

    /**
     * A column for raw SQL, with the table prefix the query builder adds to aliases.
     */
    private function column(string $column): string
    {
        return DB::getQueryGrammar()->wrap($column);
    }

    /**
     * Noob protection, as for players.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $target
     */
    private function targetAllowed(array $user, array $target, int $now): bool
    {
        if (self::asInt($target['onlinetime'] ?? 0) < $now - ONE_WEEK) {
            return true;
        }

        $noob = new NoobsProtectionLib();
        $points = $noob->returnPoints(self::asInt($user['id'] ?? 0), self::asInt($target['planet_user_id'] ?? 0));
        $own = self::asInt($points['user_points'] ?? 0);
        $theirs = self::asInt($points['target_points'] ?? 0);

        return !$noob->isWeak($own, $theirs) && !$noob->isStrong($own, $theirs);
    }

    /**
     * The ships and defences of a target row.
     *
     * @param array<string, mixed> $target
     *
     * @return array<int, int>
     */
    private function forces(array $target): array
    {
        $forces = [];

        foreach ($this->shipyard->combatUnits() as $unit => $column) {
            $amount = self::asInt($target[$column] ?? 0);

            if ($amount > 0) {
                $forces[$unit] = $amount;
            }
        }

        return $forces;
    }

    /**
     * The ships of these kinds on the planet.
     *
     * @param array<string, mixed> $planet
     * @param list<int>            $kinds
     *
     * @return array<int, int>
     */
    private function available(array $planet, array $kinds): array
    {
        $ships = [];

        foreach ($kinds as $shipId) {
            $amount = self::asInt($planet[$this->shipyard->column($shipId)] ?? 0);

            if ($amount > 0) {
                $ships[$shipId] = $amount;
            }
        }

        return $ships;
    }

    /**
     * Enough cargo ships for the loot, the big ones first.
     *
     * @param array<int, int>      $cargoShips
     * @param array<string, mixed> $user
     *
     * @return array<int, int>
     */
    private function cargoFor(array $cargoShips, float $loot, array $user): array
    {
        $fleet = [];
        $needed = $loot;

        foreach ($cargoShips as $shipId => $amount) {
            $each = $this->sender->capacity([$shipId => 1], self::asInt($user['research_hyperspace_technology'] ?? 0), $user);
            $take = $each > 0 ? min($amount, (int) ceil($needed / $each)) : 0;

            if ($take > 0) {
                $fleet[$shipId] = $take;
                $needed -= $take * $each;
            }

            if ($needed <= 0) {
                break;
            }
        }

        return $fleet;
    }

    /**
     * @param array<string, mixed> $target
     *
     * @return array{galaxy: int, system: int, planet: int, type: int}
     */
    private function coordinates(array $target): array
    {
        return [
            'galaxy' => self::asInt($target['planet_galaxy'] ?? 0),
            'system' => self::asInt($target['planet_system'] ?? 0),
            'planet' => self::asInt($target['planet_planet'] ?? 0),
            'type' => PlanetTypes::PLANET,
        ];
    }
}
