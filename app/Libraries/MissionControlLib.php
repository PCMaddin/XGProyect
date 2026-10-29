<?php

declare(strict_types=1);

namespace App\Libraries;

use Illuminate\Support\Facades\DB;
use Throwable;
use App\Core\Concerns\PreparesSql;
use App\Libraries\Missions\Acs;
use App\Libraries\Missions\Attack;
use App\Libraries\Missions\Colonize;
use App\Libraries\Missions\Deploy;
use App\Libraries\Missions\Destroy;
use App\Libraries\Missions\Expedition;
use App\Libraries\Missions\Missile;
use App\Libraries\Missions\Recycle;
use App\Libraries\Missions\Spy;
use App\Libraries\Missions\Stay;
use App\Libraries\Missions\Transport;

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
    use PreparesSql;

    /** what a mission changes about a fleet: if any of it moved, it was processed */
    private const STATE = ['fleet_mess', 'fleet_start_time', 'fleet_end_time', 'fleet_end_stay', 'fleet_array', 'fleet_amount', 'fleet_resource_metal', 'fleet_resource_crystal', 'fleet_resource_deuterium'];

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
     * One row per fleet, with the names of its start and end planet and the
     * owner's hyperspace technology.
     *
     * The planet names come from subqueries: several planets can share the
     * coordinates (a destroyed one and a new colony), and a join would return
     * the fleet once per planet. The former GROUP BY against that is rejected
     * by MariaDB in strict mode (ONLY_FULL_GROUP_BY, Laravel's default), so no
     * fleet was processed there and every game page failed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchFleets(string $whereClause): array
    {
        $rows = DB::select(
            $this->prepareSql(
                'SELECT
                    f.*,
                    (' . $this->planetName('f.`fleet_start_galaxy`', 'f.`fleet_start_system`', 'f.`fleet_start_planet`', 'f.`fleet_start_type`') . ') AS `planet_start_name`,
                    (' . $this->planetName('f.`fleet_end_galaxy`', 'f.`fleet_end_system`', 'f.`fleet_end_planet`', 'f.`fleet_end_type`') . ') AS `planet_end_name`,
                    sr.`research_hyperspace_technology`
                FROM `' . FLEETS . '` f
                LEFT JOIN `' . RESEARCH . '` sr
                    ON sr.`research_user_id` = f.`fleet_owner`
                WHERE ' . $whereClause . '
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
     * The name of the planet at the given coordinates, an intact one first.
     */
    private function planetName(string $galaxy, string $system, string $planet, string $type): string
    {
        return 'SELECT p.`planet_name`
            FROM `' . PLANETS . '` p
            WHERE p.`planet_galaxy` = ' . $galaxy . '
                AND p.`planet_system` = ' . $system . '
                AND p.`planet_planet` = ' . $planet . '
                AND p.`planet_type` = ' . $type . '
            ORDER BY p.`planet_destroyed` ASC, p.`planet_id` DESC
            LIMIT 1';
    }

    /**
     * Every page view of every player processes the fleets that are due, so
     * two views at the same moment see the same fleets. Each fleet is locked
     * and read again before its mission runs, all in one transaction: the
     * second view finds it changed (landed, turned back, gone) and leaves it.
     * Without that a returning fleet landed twice: ships and cargo doubled,
     * likewise debris, deliveries and loot.
     *
     * @param array<int, array<string, mixed>> $allFleets
     */
    private function processMissions(array $allFleets): void
    {
        foreach ($allFleets as $fleet) {
            $mission = is_numeric($fleet['fleet_mission'] ?? null) ? (int) $fleet['fleet_mission'] : 0;

            if ($this->dispatcherFor($mission, $fleet) === null) {
                continue;
            }

            try {
                DB::transaction(function () use ($fleet, $mission): void {
                    $current = $this->claim($fleet);
                    $dispatch = $current === null ? null : $this->dispatcherFor($mission, $current);

                    if ($dispatch instanceof \Closure) {
                        $dispatch();
                    }
                }, 3);
            } catch (Throwable $e) {
                // the mission runs again on the next view; the page still shows
                report($e);
            }
        }
    }

    /**
     * The fleet as it is now, locked until the transaction ends; null if
     * another view processed it since it was read.
     *
     * @param array<string, mixed> $fleet
     *
     * @return array<string, mixed>|null
     */
    private function claim(array $fleet): ?array
    {
        $row = DB::table('fleets')->where('fleet_id', $fleet['fleet_id'] ?? 0)->lockForUpdate()->first();

        if ($row === null) {
            return null;
        }

        $current = (array) $row;

        foreach (self::STATE as $column) {
            if (self::text($current[$column] ?? '') !== self::text($fleet[$column] ?? '')) {
                return null;
            }
        }

        return $current + $fleet;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
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
