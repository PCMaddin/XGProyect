<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Services\Game\FleetEventsService;
use App\Libraries\Functions;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Stay extends Missions
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Stay (hold) mission: park a fleet at a friendly planet.
     *
     * @param array<string, mixed> $fleetRow
     */
    public function stayMission(array $fleetRow): void
    {
        $friendlyPlanet = $this->getFriendlyPlanetData([
            'coords' => [
                'start' => [
                    'galaxy' => $fleetRow['fleet_start_galaxy'],
                    'system' => $fleetRow['fleet_start_system'],
                    'planet' => $fleetRow['fleet_start_planet'],
                    'type' => $fleetRow['fleet_start_type'],
                ],
                'end' => [
                    'galaxy' => $fleetRow['fleet_end_galaxy'],
                    'system' => $fleetRow['fleet_end_system'],
                    'planet' => $fleetRow['fleet_end_planet'],
                    'type' => $fleetRow['fleet_end_type'],
                ],
            ],
        ]);

        // SOME REQUIRED VALUES
        $startName = self::str($friendlyPlanet['start_name']);
        $startOwnerId = self::int($friendlyPlanet['start_id']);
        $targetName = self::str($friendlyPlanet['target_name']);
        $targetOwnerId = self::int($friendlyPlanet['target_id']);
        $metal = self::str($fleetRow['fleet_resource_metal']);
        $crystal = self::str($fleetRow['fleet_resource_crystal']);
        $deuterium = self::str($fleetRow['fleet_resource_deuterium']);

        // DIFFERENT TYPES OF MESSAGES
        $ownerMessage = sprintf(
            __('game/stay.stay_mess_owner'),
            $targetName,
            app(FleetEventsService::class)->targetLink($fleetRow, ''),
            $metal,
            $crystal,
            $deuterium
        );

        // the translation expects: origin planet, origin link, metal, crystal, deuterium
        // (the target name/link used to be passed too and shifted the amounts out of place)
        $userMessage = sprintf(
            __('game/stay.stay_mess_user'),
            $startName,
            app(FleetEventsService::class)->startLink($fleetRow, ''),
            $metal,
            $crystal,
            $deuterium
        );

        $backMessage = sprintf(
            __('game/missions.mi_fleet_back_without_resources'),
            $targetName,
            app(FleetEventsService::class)->targetLink($fleetRow, ''),
            $startName,
            app(FleetEventsService::class)->startLink($fleetRow, '')
        );

        if ($fleetRow['fleet_mess'] == 0) {
            if ($fleetRow['fleet_start_time'] <= time()) {
                $this->stayMessage(
                    $startOwnerId,
                    $ownerMessage,
                    self::int($fleetRow['fleet_start_time']),
                    __('game/stay.stay_report_title')
                );

                $this->stayMessage(
                    $targetOwnerId,
                    $userMessage,
                    self::int($fleetRow['fleet_start_time']),
                    __('game/stay.stay_report_title')
                );

                $this->updateFleetStatusToStayById($fleetRow['fleet_id']);
            }

            if ($fleetRow['fleet_end_stay'] <= time()) {
                parent::returnFleet(self::int($fleetRow['fleet_id']));
            }
        } elseif ($fleetRow['fleet_end_time'] < time()) {
            $this->stayMessage(
                $startOwnerId,
                $backMessage,
                self::int($fleetRow['fleet_end_time']),
                __('game/missions.mi_fleet_back_title')
            );

            parent::restoreFleet($fleetRow);
            parent::removeFleet(self::int($fleetRow['fleet_id']));
        }
    }

    private function stayMessage(int $owner, string $message, int $time, string $statusMessage): void
    {
        Functions::sendMessage($owner, 0, $time, 5, __('game/missions.mi_fleet_command'), $statusMessage, $message);
    }
}
