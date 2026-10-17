<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Core\Enumerators\MissionsEnumerator as Missions;
use App\Core\Enumerators\PlanetTypesEnumerator as PlanetTypes;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Libraries\NoobsProtectionLib;
use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Support\Facades\DB;

/**
 * What a bot does together with its alliance, from its home planet, after
 * {@see BotFleetPlanner} and at most one fleet a turn, the first that
 * applies:
 *
 * - defend: warships hold (mission stay) at an ally's planet an enemy
 *   fleet attacks, if they arrive first, until two hours after the attack;
 * - join an ACS attack an ally leads, if invited and the bot's warships
 *   do not hold up the group by more than 30 % of its remaining flight;
 * - fire missiles ({@see BotMissiles});
 * - lead an ACS attack on a target an ally or the bot spied on lately that
 *   is too strong for the bot alone (the raid needs three times its
 *   strength) but not for the allies nearby (one and a half times): the
 *   bot attacks with its warships and cargo ships for the loot, opens an
 *   ACS group like the federation page and invites those allies; one ACS
 *   attack of the alliance at a time, and none on a player attacked in
 *   the last day;
 * - support: half the warships hold for eight hours at the home planet of
 *   an ally raided in the last day;
 * - scout: a probe to a target within reach that no member spied on in
 *   the last six hours, so the ACS and the missiles know what they face.
 *
 * Allies are the other members of the bot's alliance ({@see BotAlliance});
 * targets are those the raids may pick, never an ally, at most 100 systems
 * away. Holding follows the fleet page's rules (allies only, at most 32
 * hours, noob protection). For all this the home planet's shipyard keeps
 * missiles and, in an alliance, warships ({@see shipyardOrder()}).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class BotCoordinator
{
    use ReadsMixedValues;

    /** systems from the home planet to an ally or a target */
    private const RANGE = 100;

    /** a report older than this is not acted on */
    private const REPORT_VALID = 3 * 3600;

    /** a target is spied on again after this long */
    private const SPY_AGAIN = 6 * 3600;

    /** how long an attack counts: the ally gets support, the target a rest */
    private const RAID_MEMORY = 86400;

    /** hours a supporting fleet holds */
    private const SUPPORT_HOURS = 8;

    /** hours a defending fleet stays after the attack is due */
    private const HOLD_AFTER = 2;

    /** the longest hold the fleet page allows */
    private const MAX_HOLD = 32;

    /** a member may hold up the group by this share of its remaining flight */
    private const MAX_DELAY = 0.3;

    /** an ACS attack flies at least this long, so the allies can join (they act every 10 to 20 minutes) */
    private const MIN_GROUP_FLIGHT = 1800;

    /** an attack must bring at least this much */
    private const MIN_LOOT = 5000;

    /** the share of the stock an attack plunders */
    private const LOOT_SHARE = 0.5;

    private const WARSHIPS = [
        Ships::SHIP_LIGHT_FIGHTER, Ships::SHIP_HEAVY_FIGHTER, Ships::SHIP_CRUISER, Ships::SHIP_BATTLESHIP,
        Ships::SHIP_BATTLECRUISER, Ships::SHIP_BOMBER, Ships::SHIP_DESTROYER,
    ];

    private const CARGO_SHIPS = [Ships::SHIP_BIG_CARGO_SHIP, Ships::SHIP_SMALL_CARGO_SHIP];

    /** the share of the stock an order of missiles or the alliance's warships may take (the next building has to wait) */
    private const FLEET_SHARE = 0.2;

    public function __construct(
        private BotFleetService $sender,
        private BotFleetPlanner $planner,
        private BotShipyard $shipyard,
        private BotBattleSimulator $simulator,
        private BotAlliance $alliance,
        private BotMissiles $missiles,
        private BotStrategy $strategy,
    ) {
    }

    /**
     * The bot's step in its alliance ({@see BotAlliance}), once a turn.
     */
    public function joinAlliance(int $botId, int $now): void
    {
        $this->alliance->act($botId, $now);
    }

    /**
     * Whether the home planet saves for its missile silo: the silo is due
     * ({@see BotStrategy::wantsSilo()}) and a target lies within missile
     * range. Building, research and the home shipyard wait meanwhile.
     *
     * @param array<string, mixed> $user
     * @param array<int, int>      $levels
     */
    public function savesForSilo(array $user, array $levels): bool
    {
        return $this->strategy->wantsSilo($levels) && $this->missiles->targetInReach($user);
    }

    /**
     * What the home planet's shipyard builds before {@see BotShipyard}'s
     * order, from a fifth of the stock (they come before the next
     * building): missiles for the silo ({@see BotMissiles}) while a target
     * is in range (warships come from {@see BotShipyard}). A wanted colony
     * ship, the defences after a raid and a probe come first. Null for
     * nothing.
     *
     * @param array<string, mixed> $user
     * @param array<int, int>      $levels
     * @param array<string, mixed> $planet the planet's row with its ships and defences
     *
     * @return array{0: int, 1: int}|null
     */
    public function shipyardOrder(array $user, array $levels, array $planet, int $now): ?array
    {
        // without a probe it cannot aim: BotShipyard builds probes first
        if (self::asInt($planet['planet_id'] ?? 0) !== self::asInt($user['home_planet_id'] ?? 0) ||
            self::asInt($planet['ship_espionage_probe'] ?? 0) === 0 ||
            $this->planner->wantsColony($user) || $this->planner->wasRaided($user, $now)) {
            return null;
        }

        $share = [];

        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $share[$resource] = self::asFloat($planet['planet_' . $resource] ?? 0) * self::FLEET_SHARE;
        }

        $missiles = $this->missiles->targetInReach($user) ? $this->missiles->order($levels, $planet, $share, MAX_FLEET_OR_DEFS_PER_ROW) : null;

        return $missiles;
    }

    /**
     * @param array<string, mixed> $user   the bot's row with research, premium and class
     * @param array<string, mixed> $planet the home planet's row with its ships and defences
     */
    public function act(array $user, array $planet, int $now): bool
    {
        if (self::asInt($planet['planet_id'] ?? 0) !== self::asInt($user['home_planet_id'] ?? 0)) {
            return false;
        }

        $allies = $this->alliance->allies($user);

        return $this->defend($user, $planet, $allies, $now) ||
            $this->joinAttack($user, $planet, $now) ||
            $this->missiles->fire($user, $planet, $allies, $now) ||
            $this->leadAttack($user, $planet, $allies, $now) ||
            $this->support($user, $planet, $allies, $now) ||
            $this->scout($user, $planet, $allies, $now);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param list<int>            $allies
     */
    private function defend(array $user, array $planet, array $allies, int $now): bool
    {
        $warships = $this->available($planet, self::WARSHIPS);

        if ($warships === [] || $allies === []) {
            return false;
        }

        $attacks = DB::table('fleets')
            ->whereIn('fleet_mission', [Missions::ATTACK, Missions::ACS])
            ->where('fleet_mess', 0)
            ->where('fleet_start_time', '>', $now)
            ->whereIn('fleet_target_owner', $allies)
            ->whereNotIn('fleet_owner', array_merge($allies, [self::asInt($user['id'] ?? 0)]))
            ->orderBy('fleet_start_time')
            ->get();

        foreach ($attacks as $attack) {
            $target = [
                'galaxy' => self::asInt($attack->fleet_end_galaxy),
                'system' => self::asInt($attack->fleet_end_system),
                'planet' => self::asInt($attack->fleet_end_planet),
                'type' => self::asInt($attack->fleet_end_type),
            ];
            $duration = $this->sender->duration($user, $planet, $warships, $target, Missions::STAY);
            $wait = self::asInt($attack->fleet_start_time) - $now - ($duration ?? PHP_INT_MAX);
            $hours = (int) ceil($wait / 3600) + self::HOLD_AFTER;

            if (!$this->near($planet, $target) || $wait <= 0 || $hours > self::MAX_HOLD || $this->holding($user, $target, $now) ||
                !$this->mayHold($user, self::asInt($attack->fleet_target_owner), $now)) {
                continue;
            }

            return $this->sender->send($user, $planet, $warships, $target, Missions::STAY, [], $hours, $now);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     */
    private function joinAttack(array $user, array $planet, int $now): bool
    {
        $userId = self::asInt($user['id'] ?? 0);
        $warships = $this->available($planet, self::WARSHIPS);

        if ($warships === []) {
            return false;
        }

        $groups = DB::table('acs AS a')
            ->join('acs_members AS m', 'm.acs_group_id', '=', 'a.acs_id')
            ->where('m.acs_user_id', $userId)
            ->where('a.acs_owner', '<>', $userId)
            ->whereNotExists(fn ($own) => $own->from('fleets AS f')->whereColumn('f.fleet_group', 'a.acs_id')->where('f.fleet_owner', $userId))
            ->select('a.*')
            ->get();

        foreach ($groups as $group) {
            $groupId = self::asInt($group->acs_id);
            $arrival = self::asInt(DB::table('fleets')->where('fleet_group', $groupId)->where('fleet_mess', 0)->max('fleet_start_time'));
            $target = [
                'galaxy' => self::asInt($group->acs_galaxy),
                'system' => self::asInt($group->acs_system),
                'planet' => self::asInt($group->acs_planet),
                'type' => self::asInt($group->acs_planet_type),
            ];
            $duration = $this->sender->duration($user, $planet, $warships, $target, Missions::ACS);

            if ($arrival <= $now || $duration === null || $now + $duration > $arrival + ($arrival - $now) * self::MAX_DELAY ||
                !$this->planner->targetAllowed($user, $this->ownerOf($target), $now)) {
                continue;
            }

            return $this->sender->send($user, $planet, $warships, $target, Missions::ACS, [], 0, $now, $groupId);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param list<int>            $allies
     */
    private function leadAttack(array $user, array $planet, array $allies, int $now): bool
    {
        $warships = $this->available($planet, self::WARSHIPS);

        // one ACS attack of the alliance at a time, until its fleets are home
        $members = array_merge([self::asInt($user['id'] ?? 0)], $allies);

        if ($allies === [] || $warships === [] || DB::table('fleets')->whereIn('fleet_owner', $members)->where('fleet_group', '<>', 0)->exists()) {
            return false;
        }

        foreach ($this->reportedTargets($user, $planet, $allies, $now) as $target) {
            $forces = $this->planner->forces($target);
            $loot = self::LOOT_SHARE * (self::asFloat($target['planet_metal'] ?? 0) + self::asFloat($target['planet_crystal'] ?? 0) + self::asFloat($target['planet_deuterium'] ?? 0));
            $fleet = $warships + $this->cargoFor($planet, $loot, $user);
            $helpers = $this->helpers($allies, $target, $now);

            // the allies need time to join
            if ($this->needsAllies($user, $target, $forces, $loot, $now) && $this->winsTogether($user, $target, $forces, $fleet, $helpers) &&
                ($this->sender->duration($user, $planet, $fleet, $this->coordinates($target), Missions::ATTACK) ?? 0) >= self::MIN_GROUP_FLIGHT) {
                return $this->openGroup($user, $planet, $target, $fleet, array_keys($helpers), $now);
            }
        }

        return false;
    }

    /**
     * A target for an ACS attack: defended, worth the flight, not attacked
     * in the last day and not protected as a noob.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $target
     * @param array<int, int>      $forces
     */
    private function needsAllies(array $user, array $target, array $forces, float $loot, int $now): bool
    {
        return $forces !== [] && $loot >= self::MIN_LOOT &&
            !$this->raided(self::asInt($target['planet_user_id'] ?? 0), $now) && $this->planner->targetAllowed($user, $target, $now);
    }

    /**
     * What the battle simulator says: the bot alone would lose (else
     * {@see BotFleetPlanner} raids alone), with the helpers' warships it wins.
     *
     * @param array<string, mixed>        $user
     * @param array<string, mixed>        $target
     * @param array<int, int>             $forces
     * @param array<int, int>             $fleet
     * @param array<int, array<int, int>> $helpers
     */
    private function winsTogether(array $user, array $target, array $forces, array $fleet, array $helpers): bool
    {
        $owner = $this->planner->ownerRow(self::asInt($target['planet_user_id'] ?? 0));

        if ($helpers === [] || $this->simulator->simulate($fleet, $user, $forces, $owner)['won']) {
            return false;
        }

        foreach ($helpers as $ships) {
            foreach ($ships as $ship => $amount) {
                $fleet[$ship] = ($fleet[$ship] ?? 0) + $amount;
            }
        }

        return $this->simulator->simulate($fleet, $user, $forces, $owner)['won'];
    }

    /**
     * The leader's attack and its ACS group, as the federation page opens
     * one: the group takes the attack's target, the leader is its first
     * member and invites the helpers.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $target
     * @param array<int, int>      $fleet
     * @param list<int>            $helpers
     */
    private function openGroup(array $user, array $planet, array $target, array $fleet, array $helpers, int $now): bool
    {
        $userId = self::asInt($user['id'] ?? 0);
        $coordinates = $this->coordinates($target);
        $groupId = DB::transaction(function () use ($userId, $coordinates): int {
            $groupId = DB::table('acs')->insertGetId([
                'acs_name' => 'AG' . random_int(100000, 999999999),
                'acs_owner' => $userId,
                'acs_galaxy' => $coordinates['galaxy'],
                'acs_system' => $coordinates['system'],
                'acs_planet' => $coordinates['planet'],
                'acs_planet_type' => $coordinates['type'],
            ]);
            DB::table('acs_members')->insert(['acs_group_id' => $groupId, 'acs_user_id' => $userId]);

            return $groupId;
        });

        if (!$this->sender->send($user, $planet, $fleet, $coordinates, Missions::ATTACK, [], 0, $now, $groupId)) {
            DB::table('acs_members')->where('acs_group_id', $groupId)->delete();
            DB::table('acs')->where('acs_id', $groupId)->delete();

            return false;
        }

        DB::table('acs_members')->insert(array_map(static fn (int $helper): array => ['acs_group_id' => $groupId, 'acs_user_id' => $helper], $helpers));

        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param list<int>            $allies
     */
    private function support(array $user, array $planet, array $allies, int $now): bool
    {
        $warships = array_map(static fn (int $amount): int => intdiv($amount + 1, 2), $this->available($planet, self::WARSHIPS));

        if ($warships === [] || $allies === []) {
            return false;
        }

        foreach ($allies as $ally) {
            $home = DB::table('users AS u')->join('planets AS p', 'p.planet_id', '=', 'u.home_planet_id')->where('u.id', $ally)->first(['p.planet_galaxy', 'p.planet_system', 'p.planet_planet']);

            if ($home === null || !$this->raided($ally, $now)) {
                continue;
            }

            $target = ['galaxy' => self::asInt($home->planet_galaxy), 'system' => self::asInt($home->planet_system), 'planet' => self::asInt($home->planet_planet), 'type' => PlanetTypes::PLANET];

            if ($this->near($planet, $target) && !$this->holding($user, $target, $now) && $this->mayHold($user, $ally, $now)) {
                return $this->sender->send($user, $planet, $warships, $target, Missions::STAY, [], self::SUPPORT_HOURS, $now);
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param list<int>            $allies
     */
    private function scout(array $user, array $planet, array $allies, int $now): bool
    {
        $armed = ($allies !== [] && $this->available($planet, self::WARSHIPS) !== []) || self::asInt($planet['defense_interplanetary_missile'] ?? 0) > 0;

        if (!$armed || self::asInt($planet['ship_espionage_probe'] ?? 0) === 0) {
            return false;
        }

        $system = self::asInt($planet['planet_system'] ?? 0);
        $spies = array_merge([self::asInt($user['id'] ?? 0)], $allies);
        $targets = $this->planner->targetQuery($user)
            ->where('p.planet_galaxy', self::asInt($planet['planet_galaxy'] ?? 0))
            ->whereBetween('p.planet_system', [$system - self::RANGE, $system + self::RANGE])
            ->whereNotExists(fn ($spied) => $spied->from('espionage_log AS e')
                ->whereColumn('e.planet_id', 'p.planet_id')
                ->whereIn('e.user_id', $spies)
                ->where('e.spied_at', '>', $now - self::SPY_AGAIN))
            ->orderByRaw('ABS(' . DB::getQueryGrammar()->wrap('p.planet_system') . ' - ?)', [$system])
            ->limit(10)
            ->get();

        foreach ($targets as $row) {
            $target = get_object_vars($row);

            if ($this->planner->targetAllowed($user, $target, $now)) {
                return $this->sender->send($user, $planet, [Ships::SHIP_ESPIONAGE_PROBE => 1], $this->coordinates($target), Missions::SPY, [], 0, $now);
            }
        }

        return false;
    }

    /**
     * Targets in reach that the bot or an ally spied on lately and that no
     * attack of the alliance flies to, the richest first.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param list<int>            $allies
     *
     * @return list<array<string, mixed>>
     */
    private function reportedTargets(array $user, array $planet, array $allies, int $now): array
    {
        $system = self::asInt($planet['planet_system'] ?? 0);
        $members = array_merge([self::asInt($user['id'] ?? 0)], $allies);
        $column = static fn (string $name): string => DB::getQueryGrammar()->wrap($name);

        return array_values(array_map(
            static fn (object $row): array => get_object_vars($row),
            $this->planner->targetQuery($user)
                ->join('defenses AS d', 'd.defense_planet_id', '=', 'p.planet_id')
                ->join('ships AS s', 's.ship_planet_id', '=', 'p.planet_id')
                ->addSelect('d.*', 's.*')
                ->where('p.planet_galaxy', self::asInt($planet['planet_galaxy'] ?? 0))
                ->whereBetween('p.planet_system', [$system - self::RANGE, $system + self::RANGE])
                ->whereExists(fn ($spied) => $spied->from('espionage_log AS e')
                    ->whereColumn('e.planet_id', 'p.planet_id')
                    ->whereIn('e.user_id', $members)
                    ->where('e.spied_at', '>', $now - self::REPORT_VALID))
                ->whereNotExists(fn ($fleets) => $fleets->from('fleets AS f')
                    ->whereIn('f.fleet_owner', $members)
                    ->whereIn('f.fleet_mission', [Missions::ATTACK, Missions::ACS])
                    ->where('f.fleet_mess', 0)
                    ->whereColumn('f.fleet_end_galaxy', 'p.planet_galaxy')
                    ->whereColumn('f.fleet_end_system', 'p.planet_system')
                    ->whereColumn('f.fleet_end_planet', 'p.planet_planet'))
                ->orderByRaw($column('p.planet_metal') . ' + ' . $column('p.planet_crystal') . ' + ' . $column('p.planet_deuterium') . ' DESC')
                ->limit(5)
                ->get()
                ->all()
        ));
    }

    /**
     * Allies whose home planet is close to the target, with the warships
     * waiting there.
     *
     * @param list<int>            $allies
     * @param array<string, mixed> $target
     *
     * @return array<int, array<int, int>>
     */
    private function helpers(array $allies, array $target, int $now): array
    {
        $helpers = [];
        $homes = DB::table('users AS u')
            ->join('planets AS p', 'p.planet_id', '=', 'u.home_planet_id')
            ->join('ships AS s', 's.ship_planet_id', '=', 'p.planet_id')
            ->join('preferences AS pr', 'pr.preference_user_id', '=', 'u.id')
            ->whereIn('u.id', $allies)
            ->where(fn ($vacation) => $vacation->whereNull('pr.preference_vacation_mode')->orWhere('pr.preference_vacation_mode', 0))
            ->select('u.id', 'u.onlinetime', 'p.*', 's.*')
            ->get();

        foreach ($homes as $row) {
            $home = get_object_vars($row);
            $warships = $this->available($home, self::WARSHIPS);

            if ($warships !== [] && self::asInt($home['onlinetime'] ?? 0) > $now - 3600 && $this->near($home, $this->coordinates($target))) {
                $helpers[self::asInt($home['id'] ?? 0)] = $warships;
            }
        }

        return $helpers;
    }

    /**
     * Whether the player was attacked in the last day: a combat report lists
     * the defender last.
     */
    private function raided(int $userId, int $now): bool
    {
        return DB::table('reports')
            ->where('report_time', '>', $now - self::RAID_MEMORY)
            ->where('report_owners', 'like', '%,' . $userId)
            ->exists();
    }

    /**
     * Noob protection forbids holding at a much stronger player, as on the
     * fleet page.
     *
     * @param array<string, mixed> $user
     */
    private function mayHold(array $user, int $ally, int $now): bool
    {
        if (self::asInt(DB::table('users')->where('id', $ally)->value('onlinetime')) < $now - ONE_WEEK) {
            return true;
        }

        $noob = new NoobsProtectionLib();
        $points = $noob->returnPoints(self::asInt($user['id'] ?? 0), $ally);

        return !$noob->isStrong(self::asInt($points['user_points'] ?? 0), self::asInt($points['target_points'] ?? 0));
    }

    /**
     * The owner of the planet and their last activity, as the noob
     * protection reads them.
     *
     * @param array{galaxy: int, system: int, planet: int, type: int} $target
     *
     * @return array<string, mixed>
     */
    private function ownerOf(array $target): array
    {
        $owner = self::asInt(DB::table('planets')
            ->where('planet_galaxy', $target['galaxy'])
            ->where('planet_system', $target['system'])
            ->where('planet_planet', $target['planet'])
            ->where('planet_type', $target['type'] === PlanetTypes::MOON ? PlanetTypes::MOON : PlanetTypes::PLANET)
            ->where('planet_destroyed', 0)
            ->value('planet_user_id'));

        return ['planet_user_id' => $owner, 'onlinetime' => DB::table('users')->where('id', $owner)->value('onlinetime')];
    }

    /**
     * Whether a fleet of the bot holds at or flies to hold at the target.
     *
     * @param array<string, mixed>                                  $user
     * @param array{galaxy: int, system: int, planet: int, type: int} $target
     */
    private function holding(array $user, array $target, int $now): bool
    {
        return DB::table('fleets')
            ->where('fleet_owner', self::asInt($user['id'] ?? 0))
            ->where('fleet_mission', Missions::STAY)
            ->where('fleet_end_galaxy', $target['galaxy'])
            ->where('fleet_end_system', $target['system'])
            ->where('fleet_end_planet', $target['planet'])
            ->where('fleet_end_stay', '>', $now)
            ->exists();
    }

    /**
     * @param array<string, mixed>                                  $planet
     * @param array{galaxy: int, system: int, planet: int, type: int} $target
     */
    private function near(array $planet, array $target): bool
    {
        return self::asInt($planet['planet_galaxy'] ?? 0) === $target['galaxy'] &&
            abs(self::asInt($planet['planet_system'] ?? 0) - $target['system']) <= self::RANGE;
    }

    /**
     * Enough cargo ships for the loot, the big ones first.
     *
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $user
     *
     * @return array<int, int>
     */
    private function cargoFor(array $planet, float $loot, array $user): array
    {
        $fleet = [];

        foreach ($this->available($planet, self::CARGO_SHIPS) as $shipId => $amount) {
            $each = $this->sender->capacity([$shipId => 1], self::asInt($user['research_hyperspace_technology'] ?? 0), $user);
            $take = $each > 0 && $loot > 0 ? min($amount, (int) ceil($loot / $each)) : 0;

            if ($take > 0) {
                $fleet[$shipId] = $take;
                $loot -= $take * $each;
            }
        }

        return $fleet;
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
