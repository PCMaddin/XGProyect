<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\Enumerators\MissionsEnumerator;
use App\Core\Enumerators\PlanetTypesEnumerator;
use App\Core\Enumerators\ShipsEnumerator;
use App\Core\GameObjects\GameObjectRegistry;
use App\Libraries\Functions;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\Formulas\FormulasService;
use App\Support\Concerns\ReadsMixedValues;
use App\Support\FleetShips;
use Illuminate\Support\Facades\DB;

/**
 * Moves a relocated planet and its moon (owiki "Umsiedeln"): the ships
 * there follow as a deployment with the normal flight time and without
 * fuel (solar satellites and crawlers move with the planet), holding fleets
 * of others fly home, the debris field stays behind, the wreck field burns
 * up and the jump gate of the moon is out of service for 24 hours; the
 * planet gets the temperature of its new position, its fields stay.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PlanetMover
{
    use ReadsMixedValues;

    public const GATE_PAUSE = 86400;

    /** ships that move with the planet */
    private const STAYING = [ShipsEnumerator::SHIP_SOLAR_SATELLITE, ShipsEnumerator::SHIP_CRAWLER];

    public function __construct(
        private GameObjectRegistry $registry,
        private FleetsService $fleets,
        private FormulasService $formulas,
        private PlayerClassService $classes,
    ) {
    }

    /**
     * @param array{0: int, 1: int, 2: int} $target
     */
    public function move(\stdClass $planet, int $moonId, array $target, int $now): void
    {
        $userId = (int) $planet->planet_user_id;
        $planetId = (int) $planet->planet_id;
        $origin = [(int) $planet->planet_galaxy, (int) $planet->planet_system, (int) $planet->planet_planet];
        $temp = $this->formulas->setPlanetTemp($target[2]);
        $place = [
            'planet_galaxy' => $target[0],
            'planet_system' => $target[1],
            'planet_planet' => $target[2],
            'planet_temp_min' => $temp['min'],
            'planet_temp_max' => $temp['max'],
        ];

        $this->sendHoldingFleetsHome($origin, $now);

        $planetShips = $this->takeShips($planetId);
        $moonShips = $moonId > 0 ? $this->takeShips($moonId) : [];

        // the debris field stays at the old position
        DB::table('planets')->where('planet_id', $planetId)->update($place + [
            'planet_debris_metal' => 0,
            'planet_debris_crystal' => 0,
            'planet_relocate_galaxy' => 0,
            'planet_relocate_system' => 0,
            'planet_relocate_planet' => 0,
            'planet_relocate_at' => 0,
        ]);

        if ($moonId > 0) {
            DB::table('planets')->where('planet_id', $moonId)->update($place + ['planet_last_jump_time' => $this->gatePause($moonId, $now)]);
        }

        DB::table('users')->where('id', $userId)->where('home_planet_id', $planetId)->update(['galaxy' => $target[0], 'system' => $target[1], 'planet' => $target[2]]);
        DB::table('wreck_fields')->where('planet_id', $planetId)->delete();

        $this->sendShips($userId, $planetShips, $origin, $target, PlanetTypesEnumerator::PLANET, $now);
        $this->sendShips($userId, $moonShips, $origin, $target, PlanetTypesEnumerator::MOON, $now);
    }

    /**
     * The last jump so that the gate is ready again in 24 hours.
     */
    private function gatePause(int $moonId, int $now): int
    {
        $level = self::asInt(DB::table('buildings')->where('building_planet_id', $moonId)->value('building_jump_gate'));

        return $now + self::GATE_PAUSE - ($level > 0 ? intdiv(3600, $level) : 0);
    }

    /**
     * Fleets of others holding at the planet or its moon fly home.
     *
     * @param array{0: int, 1: int, 2: int} $origin
     */
    private function sendHoldingFleetsHome(array $origin, int $now): void
    {
        $fleets = DB::table('fleets')
            ->where('fleet_mission', MissionsEnumerator::STAY)
            ->where('fleet_mess', 0)
            ->where('fleet_end_galaxy', $origin[0])
            ->where('fleet_end_system', $origin[1])
            ->where('fleet_end_planet', $origin[2])
            ->get();

        foreach ($fleets as $fleet) {
            $creation = (int) $fleet->fleet_creation;
            $flight = (int) $fleet->fleet_start_time - $creation;
            $elapsed = $now - $creation;

            DB::table('fleets')->where('fleet_id', $fleet->fleet_id)->update([
                'fleet_start_time' => $now,
                'fleet_end_stay' => 0,
                'fleet_end_time' => $now + min($elapsed, $flight),
                'fleet_target_owner' => (int) $fleet->fleet_owner,
                'fleet_mess' => 1,
            ]);
        }
    }

    /**
     * Take the ships that fly after the planet off it.
     *
     * @return array<int, int>
     */
    private function takeShips(int $planetId): array
    {
        $row = DB::table('ships')->where('ship_planet_id', $planetId)->lockForUpdate()->first();

        if ($row === null) {
            return [];
        }

        $ships = [];
        $columns = [];

        foreach ($this->registry->ships() as $ship) {
            $amount = (int) ($row->{$ship->getName()} ?? 0);

            if ($amount > 0 && !in_array($ship->getId(), self::STAYING, true)) {
                $ships[$ship->getId()] = $amount;
                $columns[$ship->getName()] = 0;
            }
        }

        if ($columns !== []) {
            DB::table('ships')->where('ship_planet_id', $planetId)->update($columns);
        }

        return $ships;
    }

    /**
     * The ships deploy to the new position with the normal flight time and
     * without fuel.
     *
     * @param array<int, int>         $ships
     * @param array{0: int, 1: int, 2: int} $origin
     * @param array{0: int, 1: int, 2: int} $target
     */
    private function sendShips(int $userId, array $ships, array $origin, array $target, int $type, int $now): void
    {
        if ($ships === []) {
            return;
        }

        $duration = $this->flightTime($userId, $ships, $origin, $target);

        DB::table('fleets')->insert([
            'fleet_owner' => $userId,
            'fleet_mission' => MissionsEnumerator::DEPLOY,
            'fleet_amount' => array_sum($ships),
            'fleet_array' => FleetShips::encode($ships),
            'fleet_start_time' => $now + $duration,
            'fleet_start_galaxy' => $origin[0],
            'fleet_start_system' => $origin[1],
            'fleet_start_planet' => $origin[2],
            'fleet_start_type' => $type,
            'fleet_end_time' => $now + (2 * $duration),
            'fleet_end_galaxy' => $target[0],
            'fleet_end_system' => $target[1],
            'fleet_end_planet' => $target[2],
            'fleet_end_type' => $type,
            'fleet_target_owner' => $userId,
            'fleet_creation' => $now,
        ]);
    }

    /**
     * @param array<int, int>         $ships
     * @param array{0: int, 1: int, 2: int} $origin
     * @param array{0: int, 1: int, 2: int} $target
     */
    private function flightTime(int $userId, array $ships, array $origin, array $target): int
    {
        $research = DB::table('research')->where('research_user_id', $userId)->first();
        $speeds = $this->fleets->fleetMaxSpeed(
            $ships,
            (int) ($research->research_combustion_drive ?? 0),
            (int) ($research->research_impulse_drive ?? 0),
            (int) ($research->research_hyperspace_drive ?? 0),
            $this->classes->bonusesOf($userId)
        );
        $distance = $this->fleets->targetDistance($origin[0], $target[0], $origin[1], $target[1], $origin[2], $target[2]);

        $slowest = $speeds === [] ? 1.0 : min($speeds);

        return (int) $this->fleets->missionDuration(10, max(1.0, $slowest), $distance, max(1, Functions::fleetSpeedFactor()));
    }
}
