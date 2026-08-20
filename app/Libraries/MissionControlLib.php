<?php

declare(strict_types=1);

namespace App\Libraries;

use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Libraries\Missions\Acs;
use Xgp\App\Libraries\Missions\Attack;
use Xgp\App\Libraries\Missions\Colonize;
use Xgp\App\Libraries\Missions\Deploy;
use Xgp\App\Libraries\Missions\Destroy;
use Xgp\App\Libraries\Missions\Expedition;
use Xgp\App\Libraries\Missions\Missile;
use Xgp\App\Libraries\Missions\Recycle;
use Xgp\App\Libraries\Missions\Spy;
use Xgp\App\Libraries\Missions\Stay;
use Xgp\App\Libraries\Missions\Transport;

/**
 * Fleet-mission dispatcher for the tick. Ported from the legacy static class:
 * the reflective `$mission->$name($fleet)` call on a string-built class name is
 * replaced by an explicit, type-checked mapping to the concrete mission class.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class MissionControlLib
{
    use PreparesLegacySql;

    public function arrivingFleets(): void
    {
        $this->processMissions($this->getArrivingFleets());
    }

    public function returningFleets(): void
    {
        $this->processMissions($this->getReturningFleets());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getArrivingFleets(): array
    {
        return $this->fetchFleets('f.`fleet_start_time` <= ? AND f.`fleet_mess` = \'0\'');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getReturningFleets(): array
    {
        return $this->fetchFleets('f.`fleet_end_time` <= ? AND f.`fleet_mess` <> \'0\'');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFleets(string $whereClause): array
    {
        $rows = DB::select(
            $this->prepareSql(
                'SELECT
                    f.*,
                    sp.`planet_name` AS `planet_start_name`,
                    ep.`planet_name` AS `planet_end_name`,
                    sr.`research_hyperspace_technology`
                FROM `' . FLEETS . '` f
                LEFT JOIN `' . PLANETS . '` sp
                    ON (sp.`planet_galaxy` = f.`fleet_start_galaxy` AND
                        sp.`planet_system` = f.`fleet_start_system` AND
                        sp.`planet_planet` = f.`fleet_start_planet` AND
                        sp.`planet_type` = f.`fleet_start_type`)
                LEFT JOIN `' . RESEARCH . '` sr
                    ON sr.`research_user_id` = f.`fleet_owner`
                LEFT JOIN `' . PLANETS . '` ep
                    ON (ep.`planet_galaxy` = f.`fleet_end_galaxy` AND
                        ep.`planet_system` = f.`fleet_end_system` AND
                        ep.`planet_planet` = f.`fleet_end_planet` AND
                        ep.`planet_type` = f.`fleet_end_type`)
                WHERE ' . $whereClause . '
                GROUP BY f.`fleet_id`, sp.`planet_name`, ep.`planet_name`
                ORDER BY f.`fleet_id` ASC'
            ),
            [time()]
        );

        return array_map(
            static fn (object $row): array => (array) $row,
            $rows
        );
    }

    /**
     * @param array<int, array<string, mixed>> $allFleets
     */
    private function processMissions(array $allFleets): void
    {
        foreach ($allFleets as $fleet) {
            $mission = is_numeric($fleet['fleet_mission'] ?? null) ? (int) $fleet['fleet_mission'] : 0;
            $dispatch = $this->dispatcherFor($mission, $fleet);

            if ($dispatch instanceof \Closure) {
                $dispatch();
            }
        }
    }

    /**
     * Maps a mission id to the concrete handler call. Unknown ids are skipped.
     *
     * @param array<string, mixed> $fleet
     */
    private function dispatcherFor(int $mission, array $fleet): ?\Closure
    {
        return match ($mission) {
            1 => static function () use ($fleet): void {
                app(Attack::class)->attackMission($fleet);
            },
            2 => static function () use ($fleet): void {
                app(Acs::class)->acsMission($fleet);
            },
            3 => static function () use ($fleet): void {
                app(Transport::class)->transportMission($fleet);
            },
            4 => static function () use ($fleet): void {
                app(Deploy::class)->deployMission($fleet);
            },
            5 => static function () use ($fleet): void {
                app(Stay::class)->stayMission($fleet);
            },
            6 => static function () use ($fleet): void {
                app(Spy::class)->spyMission($fleet);
            },
            7 => static function () use ($fleet): void {
                app(Colonize::class)->colonizeMission($fleet);
            },
            8 => static function () use ($fleet): void {
                app(Recycle::class)->recycleMission($fleet);
            },
            9 => static function () use ($fleet): void {
                app(Destroy::class)->destroyMission($fleet);
            },
            10 => static function () use ($fleet): void {
                app(Missile::class)->missileMission($fleet);
            },
            15 => static function () use ($fleet): void {
                app(Expedition::class)->expeditionMission($fleet);
            },
            default => null,
        };
    }
}
