<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Services\FormatService;
use App\Helpers\StringsHelper;
use App\Libraries\FleetsLib as Fleets;
use App\Libraries\Functions;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Deploy extends Missions
{
    public function __construct(private FormatService $formatService)
    {
        parent::__construct();
    }

    /**
     * Deploy mission - move fleets from one planet to another
     *
     * @param array<string, mixed> $fleet
     */
    public function deployMission(array $fleet): void
    {
        // by default we send ships to the target
        $startPlanet = false;

        // do mission
        if (parent::canStartMission($fleet)) {
            // message
            $this->sendDeploymentMessage($fleet);
        } elseif (parent::canCompleteMission($fleet)) {
            // in this case, complete mission = cancel mission,
            // since deployment can only go one way, except if the fleet it's returned
            $startPlanet = true;

            // message
            $this->sendReturnMessage($fleet);
        }

        // transfer the ships to the planet
        $this->landShips($fleet, $startPlanet);
        parent::removeFleet(self::int($fleet['fleet_id']));
    }

    /**
     * @param array<string, mixed> $fleet
     */
    private function landShips(array $fleet, bool $returned): void
    {
        if ($returned) {
            parent::restoreFleet($fleet);

            return;
        }

        parent::restoreFleetAtTarget($fleet);
    }

    /**
     * Send a deploymeny message to the fleet owner
     *
     * @param array<string, mixed> $fleet
     */
    private function sendDeploymentMessage(array $fleet): void
    {
        // send message
        Functions::sendMessage(
            self::int($fleet['fleet_owner']),
            0,
            self::int($fleet['fleet_start_time']),
            5,
            __('game/missions.mi_fleet_command'),
            __('game/deploy.dep_report_title'),
            StringsHelper::parseReplacements(__('game/deploy.dep_report_deployed'), [
                $fleet['planet_start_name'],
                Fleets::startLink($fleet, ''),
                $fleet['planet_end_name'],
                Fleets::targetLink($fleet, ''),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_metal'])),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_crystal'])),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_deuterium'])),
            ])
        );
    }

    /**
     * Send a message informing that the fleet is back
     *
     * @param array<string, mixed> $fleet
     */
    private function sendReturnMessage(array $fleet): void
    {
        $text = __('game/deploy.dep_report_back');
        $replacements = [
            $fleet['planet_end_name'],
            Fleets::targetLink($fleet, ''),
            $fleet['planet_start_name'],
            Fleets::startLink($fleet, ''),
        ];

        if (Fleets::hasResources($fleet)) {
            $text = __('game/deploy.dep_report_deployed');
            $replacements = [
                $fleet['planet_end_name'],
                Fleets::targetLink($fleet, ''),
                $fleet['planet_start_name'],
                Fleets::startLink($fleet, ''),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_metal'])),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_crystal'])),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_deuterium'])),
            ];
        }

        // send message
        Functions::sendMessage(
            self::int($fleet['fleet_owner']),
            0,
            self::int($fleet['fleet_end_time']),
            5,
            __('game/missions.mi_fleet_command'),
            __('game/deploy.dep_report_title'),
            StringsHelper::parseReplacements($text, $replacements)
        );
    }
}
