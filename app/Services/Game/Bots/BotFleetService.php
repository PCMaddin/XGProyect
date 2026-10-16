<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Core\Concerns\PreparesSql;
use App\Core\Enumerators\MissionsEnumerator as Missions;
use App\Core\GameObjects\GameObjectRegistry;
use App\Libraries\Functions;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\PlayerClassService;
use App\Services\Game\SystemEspionageService;
use App\Support\Concerns\ReadsMixedValues;
use App\Support\FleetShips;
use App\Support\OfficerBonuses;
use Illuminate\Support\Facades\DB;

/**
 * Sends a bot's fleet the way the fleet pages do for a player: the ships
 * must be on the planet, a fleet (and expedition) slot free, the cargo must
 * fit and the deuterium cover the flight; flight time and fuel use the
 * same formulas. The bot always flies at full speed.
 *
 * Which fleet goes where is decided by {@see BotFleetPlanner}; this class
 * only checks the rules and books the fleet.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class BotFleetService
{
    use PreparesSql;
    use ReadsMixedValues;

    /** full speed, in tenths */
    private const SPEED = 10.0;

    public function __construct(
        private FleetsService $fleets,
        private GameObjectRegistry $registry,
        private SystemEspionageService $espionage,
    ) {
    }

    /**
     * @param array<string, mixed>                                  $user   the bot's row with research, premium and class
     * @param array<string, mixed>                                  $planet the start planet's row with its ships
     * @param array<int, int>                                       $ships  ship id => amount
     * @param array{galaxy: int, system: int, planet: int, type: int} $target
     * @param array<string, int>                                    $cargo  metal, crystal, deuterium
     */
    public function send(array $user, array $planet, array $ships, array $target, int $mission, array $cargo, int $stayHours, int $now): bool
    {
        $ships = array_filter($ships, static fn (int $amount): bool => $amount > 0);

        if ($ships === [] || !$this->shipsOnPlanet($planet, $ships) || !$this->slotFree($user, $mission, $now)) {
            return false;
        }

        $flight = $this->flight($user, $planet, $ships, $target, $mission);
        $metal = max(0, $cargo['metal'] ?? 0);
        $crystal = max(0, $cargo['crystal'] ?? 0);
        $deuterium = max(0, $cargo['deuterium'] ?? 0);

        if ($flight === null || !$this->fits($user, $planet, $ships, [$metal, $crystal, $deuterium + $flight['fuel']], $metal + $crystal + $deuterium)) {
            return false;
        }

        [$duration, $fuel] = [$flight['duration'], $flight['fuel']];
        $startTime = $now + $duration;
        $stayTime = in_array($mission, [Missions::EXPEDITION, Missions::STAY], true) && $stayHours > 0 ? $startTime + $stayHours * 3600 : 0;

        $this->book($user, $planet, $ships, $target, [
            'fleet_owner' => self::asInt($user['id'] ?? 0),
            'fleet_mission' => $mission,
            'fleet_amount' => array_sum($ships),
            'fleet_array' => FleetShips::encode($ships),
            'fleet_start_time' => $startTime,
            'fleet_start_galaxy' => self::asInt($planet['planet_galaxy'] ?? 0),
            'fleet_start_system' => self::asInt($planet['planet_system'] ?? 0),
            'fleet_start_planet' => self::asInt($planet['planet_planet'] ?? 0),
            'fleet_start_type' => self::asInt($planet['planet_type'] ?? 1),
            'fleet_end_time' => $now + 2 * $duration + ($stayTime > 0 ? $stayHours * 3600 : 0),
            'fleet_end_stay' => $stayTime,
            'fleet_end_galaxy' => $target['galaxy'],
            'fleet_end_system' => $target['system'],
            'fleet_end_planet' => $target['planet'],
            'fleet_end_type' => $target['type'],
            'fleet_resource_metal' => $metal,
            'fleet_resource_crystal' => $crystal,
            'fleet_resource_deuterium' => $deuterium,
            'fleet_fuel' => $fuel,
            'fleet_target_owner' => $this->targetOwner($target),
            'fleet_creation' => $now,
        ], $now);

        return true;
    }

    /**
     * Flight time one way and fuel at full speed, or null for ships that
     * cannot fly.
     *
     * @param array<string, mixed>                                  $user
     * @param array<string, mixed>                                  $planet
     * @param array<int, int>                                       $ships
     * @param array{galaxy: int, system: int, planet: int, type: int} $target
     *
     * @return array{duration: int, fuel: int}|null
     */
    private function flight(array $user, array $planet, array $ships, array $target, int $mission): ?array
    {
        $bonuses = PlayerClassService::bonusesFromRow($user);
        $combustion = self::asInt($user['research_combustion_drive'] ?? 0);
        $impulse = self::asInt($user['research_impulse_drive'] ?? 0);
        $hyperspace = self::asInt($user['research_hyperspace_drive'] ?? 0);
        $speeds = $this->fleets->fleetMaxSpeed($ships, $combustion, $impulse, $hyperspace, $bonuses);

        if ($speeds === [] || min($speeds) <= 0) {
            return null;
        }

        $distance = $this->fleets->targetDistance(
            self::asInt($planet['planet_galaxy'] ?? 0),
            $target['galaxy'],
            self::asInt($planet['planet_system'] ?? 0),
            $target['system'],
            self::asInt($planet['planet_planet'] ?? 0),
            $target['planet']
        );
        $speedFactor = Functions::fleetSpeedFactor();
        $minSpeed = (float) min($speeds);
        $classFactor = $mission === Missions::EXPEDITION ? $bonuses->expeditionSpeedFactor() : 1.0;

        return [
            'duration' => (int) floor($this->fleets->missionDuration(self::SPEED, $minSpeed * $classFactor, $distance, $speedFactor)),
            'fuel' => (int) $this->fleets->fleetConsumption(
                $ships,
                $speedFactor,
                (int) $this->fleets->missionDuration(self::SPEED, $minSpeed, $distance, $speedFactor),
                $distance,
                $combustion,
                $impulse,
                $hyperspace,
                $bonuses
            ),
        ];
    }

    /**
     * Whether the cargo fits into the ships and the planet has the
     * resources, the fuel included.
     *
     * @param array<string, mixed>   $user
     * @param array<string, mixed>   $planet
     * @param array<int, int>        $ships
     * @param array{int, int, int}   $needed metal, crystal, deuterium with fuel
     */
    private function fits(array $user, array $planet, array $ships, array $needed, int $cargo): bool
    {
        return $cargo <= $this->capacity($ships, self::asInt($user['research_hyperspace_technology'] ?? 0), $user) &&
            $needed[0] <= self::asFloat($planet['planet_metal'] ?? 0) &&
            $needed[1] <= self::asFloat($planet['planet_crystal'] ?? 0) &&
            $needed[2] <= self::asFloat($planet['planet_deuterium'] ?? 0);
    }

    /**
     * What the ships can carry, with hyperspace technology and class bonus.
     *
     * @param array<int, int>      $ships
     * @param array<string, mixed> $user
     */
    public function capacity(array $ships, int $hyperspaceTechnology, array $user): int
    {
        $bonuses = PlayerClassService::bonusesFromRow($user);
        $capacity = 0;

        foreach ($ships as $shipId => $amount) {
            $capacity += $this->fleets->getMaxStorage($this->registry->findShip($shipId)?->getCapacity() ?? 0, $hyperspaceTechnology, $shipId, $bonuses) * $amount;
        }

        return $capacity;
    }

    /**
     * The fleet and expedition slots in use and available.
     *
     * @param array<string, mixed> $user
     */
    public function slotFree(array $user, int $mission, int $now): bool
    {
        $bonuses = PlayerClassService::bonusesFromRow($user);
        $officers = OfficerBonuses::fromRow($user, $now);
        $flying = DB::table('fleets')->where('fleet_owner', self::asInt($user['id'] ?? 0));

        if ((clone $flying)->count() >= $this->fleets->getMaxFleets(self::asInt($user['research_computer_technology'] ?? 0), $officers, $bonuses)) {
            return false;
        }

        return $mission !== Missions::EXPEDITION ||
            $flying->where('fleet_mission', Missions::EXPEDITION)->count()
                < $this->fleets->getMaxExpeditions(self::asInt($user['research_astrophysics'] ?? 0), $bonuses, $officers);
    }

    /**
     * @param array<string, mixed> $planet
     * @param array<int, int>      $ships
     */
    private function shipsOnPlanet(array $planet, array $ships): bool
    {
        $names = $this->registry->names();

        foreach ($ships as $shipId => $amount) {
            if ($this->registry->findShip($shipId) === null || $amount > self::asInt($planet[$names[$shipId] ?? ''] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{galaxy: int, system: int, planet: int, type: int} $target
     */
    private function targetOwner(array $target): int
    {
        return self::asInt(DB::table('planets')
            ->where('planet_galaxy', $target['galaxy'])
            ->where('planet_system', $target['system'])
            ->where('planet_planet', $target['planet'])
            ->where('planet_type', $target['type'] === 2 ? 1 : $target['type'])
            ->where('planet_destroyed', 0)
            ->value('planet_user_id'));
    }

    /**
     * The fleet row, the ships and resources off the planet, as one step.
     *
     * @param array<string, mixed>                                  $user
     * @param array<string, mixed>                                  $planet
     * @param array<int, int>                                       $ships
     * @param array{galaxy: int, system: int, planet: int, type: int} $target
     * @param array<string, int|string>                             $fleet
     */
    private function book(array $user, array $planet, array $ships, array $target, array $fleet, int $now): void
    {
        $names = $this->registry->names();

        DB::transaction(function () use ($user, $planet, $ships, $target, $fleet, $names, $now): void {
            DB::table('fleets')->insert($fleet);

            $assignments = [];
            $bindings = [];

            foreach ($ships as $shipId => $amount) {
                $assignments[] = '`' . $names[$shipId] . '` = `' . $names[$shipId] . '` - ?';
                $bindings[] = $amount;
            }

            array_push(
                $bindings,
                self::asInt($fleet['fleet_resource_metal']),
                self::asInt($fleet['fleet_resource_crystal']),
                self::asInt($fleet['fleet_resource_deuterium']) + self::asInt($fleet['fleet_fuel']),
                self::asInt($planet['planet_id'] ?? 0)
            );

            DB::update(
                $this->prepareSql(
                    'UPDATE `' . PLANETS . '` AS p
                    INNER JOIN `' . SHIPS . '` AS s ON s.`ship_planet_id` = p.`planet_id` SET
                    ' . implode(', ', $assignments) . ',
                    `planet_metal` = `planet_metal` - ?, `planet_crystal` = `planet_crystal` - ?,
                    `planet_deuterium` = `planet_deuterium` - ?
                    WHERE `planet_id` = ?;'
                ),
                $bindings
            );

            if (self::asInt($fleet['fleet_mission']) === Missions::SPY) {
                $this->espionage->recordAt(self::asInt($user['id'] ?? 0), $target['galaxy'], $target['system'], $target['planet'], $target['type'], $now);
            }
        });
    }
}
