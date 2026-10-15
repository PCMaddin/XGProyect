<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

use App\Services\Game\Formulas\FleetsService;
use App\Libraries\FleetsLib;
use App\Libraries\Functions;
use App\Libraries\PlanetLib;
use App\Libraries\StatisticsLibrary;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Colonize extends Missions
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    public function colonizeMission(array $fleetRow): void
    {
        if ($fleetRow['fleet_mess'] == 0) {
            $this->tryToColonize($fleetRow);
        }

        if ($fleetRow['fleet_end_time'] < time()) {
            parent::restoreFleet($fleetRow);
            parent::removeFleet(self::int($fleetRow['fleet_id']));
        }
    }

    /**
     * Try to found a colony on the fleet's target; the fleet returns when that is not possible.
     *
     * @param array<string, mixed> $fleetRow
     */
    private function tryToColonize(array $fleetRow): void
    {
        $colonizationCheck = $this->getPlanetAndUserCountsCounts([
            'id' => $fleetRow['fleet_owner'],
            'coords' => [
                'galaxy' => $fleetRow['fleet_end_galaxy'],
                'system' => $fleetRow['fleet_end_system'],
                'planet' => $fleetRow['fleet_end_planet'],
            ],
        ]);

        // some required values
        $owner = self::int($fleetRow['fleet_owner']);
        $fleetId = self::int($fleetRow['fleet_id']);
        $startTime = self::int($fleetRow['fleet_start_time']);
        $endTime = self::int($fleetRow['fleet_end_time']);
        $astroLevel = self::int($colonizationCheck['astro_level']);
        $targetCoords = sprintf(
            __('game/missions.mi_planet_coordinates'),
            self::str($fleetRow['fleet_end_galaxy']),
            self::str($fleetRow['fleet_end_system']),
            self::str($fleetRow['fleet_end_planet'])
        );
        $maxColonies = app(FleetsService::class)->getMaxColonies($astroLevel);
        // the total amount of planets minus 1 (because the main planet is not considered)
        $planetCount = self::int($colonizationCheck['planet_count']) - 1;

        if ($colonizationCheck['galaxy_count'] != 0) {
            $this->colonizeMessage($owner, sprintf(__('game/colonize.col_occupied'), $targetCoords), $endTime);
            parent::returnFleet($fleetId);

            return;
        }

        if ($planetCount >= $maxColonies) {
            $this->colonizeMessage($owner, sprintf(__('game/colonize.col_max_colonies'), $targetCoords, ($maxColonies + 1)), $startTime);
            parent::returnFleet($fleetId);

            return;
        }

        if (!$this->positionAllowed(self::int($fleetRow['fleet_end_planet']), $astroLevel)) {
            $this->colonizeMessage($owner, sprintf(__('game/colonize.col_astro_level'), $targetCoords), $startTime);
            parent::returnFleet($fleetId);

            return;
        }

        if (!$this->startCreation($fleetRow)) {
            $this->colonizeMessage($owner, sprintf(__('game/colonize.col_occupied'), $targetCoords), $endTime);
            parent::returnFleet($fleetId);

            return;
        }

        $this->colonizeMessage($owner, sprintf(__('game/colonize.col_successful'), $targetCoords), $startTime);

        if ($fleetRow['fleet_amount'] == 1) {
            $this->updateColonizationStatistics([
                'points' => StatisticsLibrary::calculatePoints(208, 1),
                'coords' => [
                    'galaxy' => $fleetRow['fleet_start_galaxy'],
                    'system' => $fleetRow['fleet_start_system'],
                    'planet' => $fleetRow['fleet_start_planet'],
                    'type' => $fleetRow['fleet_start_type'],
                ],
            ]);
            parent::storeResources($fleetRow);
            parent::removeFleet($fleetId);

            return;
        }

        parent::storeResources($fleetRow);

        $this->updateColonizatonReturningFleet([
            'ships' => $this->buildNewFleet(self::str($fleetRow['fleet_array'])),
            'points' => StatisticsLibrary::calculatePoints(208, 1),
            'fleet_id' => $fleetRow['fleet_id'],
            'coords' => [
                'galaxy' => $fleetRow['fleet_start_galaxy'],
                'system' => $fleetRow['fleet_start_system'],
                'planet' => $fleetRow['fleet_start_planet'],
                'type' => $fleetRow['fleet_start_type'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    private function startCreation(array $fleetRow): bool
    {
        $creator = new PlanetLib();

        return $creator->setNewPlanet(
            self::int($fleetRow['fleet_end_galaxy']),
            self::int($fleetRow['fleet_end_system']),
            self::int($fleetRow['fleet_end_planet']),
            self::int($fleetRow['fleet_owner'])
        );
    }

    /**
     * The serialized fleet without the colony ship that founded the colony.
     *
     * Bugfix: this used to be declared `: bool` while returning the serialized
     * string, which under strict_types threw a TypeError for every colonization
     * that carried more than the colony ship.
     */
    private function buildNewFleet(string $fleetArray): string
    {
        $newFleet = [];

        foreach (FleetsLib::getFleetShipsArray($fleetArray) as $ship => $count) {
            // one colony ship (208) stays behind as the new colony
            if ($ship == 208) {
                if ($count > 1) {
                    $newFleet[$ship] = $count - 1;
                }

                continue;
            }

            if ($count != 0) {
                $newFleet[$ship] = $count;
            }
        }

        return FleetsLib::setFleetShipsArray($newFleet);
    }

    private function colonizeMessage(int $owner, string $message, int $time): void
    {
        Functions::sendMessage($owner, 0, $time, 5, __('game/colonize.col_report_from'), __('game/colonize.col_report_title'), $message);
    }

    /**
     * Check if position is allowed
     */
    private function positionAllowed(int $position, int $level): bool
    {
        // positions 4 to 12 can always be populated
        if ($position > 3 && $position < 13) {
            return true;
        }

        // positions 3 and 13 can be populated from level 4 onwards
        if ($level >= 4 && ($position == 3 || $position == 13)) {
            return true;
        }

        // positions 2 and 14 can be populated from level 6 onwards
        if ($level >= 6 && ($position == 2 || $position == 14)) {
            return true;
        }

        // positions 1 and 15 can be populated from level 8 onwards
        return $level >= 8;
    }
}
