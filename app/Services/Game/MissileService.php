<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\Concerns\PreparesSql;
use App\Core\Enumerators\DefensesEnumerator as Defenses;
use App\Core\Enumerators\MissionsEnumerator as Missions;
use App\Services\SettingsService;
use App\Support\FleetShips;
use Illuminate\Support\Facades\DB;

/**
 * Launching interplanetary missiles, as the galaxy page does it for players
 * and the bots for themselves: the missiles leave the planet's silo and
 * fly as a fleet (mission missile) that strikes on arrival. The page checks
 * silo, impulse drive, range and target before.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class MissileService
{
    use PreparesSql;

    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * Seconds a missile flies over this many systems.
     */
    public function flightTime(int $distance): int
    {
        return (int) round(((30 + (60 * $distance)) * 2500) / max($this->settings->getInt('fleet_speed'), 1));
    }

    /**
     * @param array{galaxy: int, system: int, planet: int} $from      the launching planet
     * @param array{galaxy: int, system: int, planet: int} $to        the target planet
     * @param int                                          $targetObj the defence hit first (0: all in order)
     */
    public function launch(int $ownerId, int $planetId, array $from, array $to, int $amount, int $targetObj, int $targetOwner, int $now): void
    {
        $flightTime = $this->flightTime(abs($to['system'] - $from['system']));

        DB::transaction(function () use ($ownerId, $planetId, $from, $to, $amount, $targetObj, $targetOwner, $now, $flightTime): void {
            DB::insert(
                $this->prepareSql(
                    'INSERT INTO `' . FLEETS . '` SET
                        `fleet_owner` = ?, `fleet_mission` = ?, `fleet_amount` = ?, `fleet_array` = ?,
                        `fleet_start_time` = ?, `fleet_start_galaxy` = ?, `fleet_start_system` = ?,
                        `fleet_start_planet` = ?, `fleet_start_type` = 1, `fleet_end_time` = ?,
                        `fleet_end_stay` = 0, `fleet_end_galaxy` = ?, `fleet_end_system` = ?,
                        `fleet_end_planet` = ?, `fleet_end_type` = 1, `fleet_target_obj` = ?,
                        `fleet_resource_metal` = 0, `fleet_resource_crystal` = 0, `fleet_resource_deuterium` = 0,
                        `fleet_target_owner` = ?, `fleet_group` = 0, `fleet_mess` = 0, `fleet_creation` = ?;'
                ),
                [
                    $ownerId,
                    Missions::MISSILE,
                    $amount,
                    FleetShips::encode([Defenses::DEFENSE_INTERPLANETARY_MISSILE => $amount]),
                    $now + $flightTime,
                    $from['galaxy'],
                    $from['system'],
                    $from['planet'],
                    $now + $flightTime + 1,
                    $to['galaxy'],
                    $to['system'],
                    $to['planet'],
                    $targetObj,
                    $targetOwner,
                    $now,
                ]
            );

            DB::update(
                $this->prepareSql(
                    'UPDATE `' . DEFENSES . '` SET
                        `defense_interplanetary_missile` = `defense_interplanetary_missile` - ?
                    WHERE `defense_planet_id` = ?;'
                ),
                [$amount, $planetId]
            );
        });
    }
}
