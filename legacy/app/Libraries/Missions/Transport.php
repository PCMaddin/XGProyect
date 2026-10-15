<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

use App\Services\FormatService;
use Xgp\App\Helpers\StringsHelper;
use App\Libraries\FleetsLib as Fleets;
use App\Libraries\Functions;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Transport extends Missions
{
    public function __construct(private FormatService $formatService)
    {
        parent::__construct();
    }

    /**
     * Transport mission - deliver resources between planets
     *
     * @param array<string, mixed> $fleet
     */
    public function transportMission(array $fleet): void
    {
        // get required data
        $tradingPlanets = $this->getTradingPlanetsData($fleet);

        // do mission
        if (parent::canStartMission($fleet)) {
            // messages
            $this->sendDeliveryMessageToOwner($fleet, $tradingPlanets);
            $this->sendDeliveryMessageToReceiver($fleet, $tradingPlanets);

            // transfer the fleet resources to the planet
            parent::storeResources($fleet, false);
            $this->updateReturningFleetResources(self::int($fleet['fleet_id']));

            // we need to remove the resources in case we are continuing with the
            // canCompleteMission instead of just ending here
            $fleet['fleet_resource_metal'] = 0;
            $fleet['fleet_resource_crystal'] = 0;
            $fleet['fleet_resource_deuterium'] = 0;
        }

        // complete mission
        if (parent::canCompleteMission($fleet)) {
            // message
            $this->sendReturnMessage($fleet, $tradingPlanets);

            // transfer the ships to the planet
            parent::restoreFleet($fleet);
            parent::removeFleet(self::int($fleet['fleet_id']));
        }
    }

    /**
     * Get data for the planets that are trading resources
     *
     * @param array<string, mixed> $fleet
     *
     * @return array<string, mixed>
     */
    private function getTradingPlanetsData(array $fleet): array
    {
        return $this->getFriendlyPlanetData([
            'coords' => [
                'start' => [
                    'galaxy' => $fleet['fleet_start_galaxy'],
                    'system' => $fleet['fleet_start_system'],
                    'planet' => $fleet['fleet_start_planet'],
                    'type' => $fleet['fleet_start_type'],
                ],
                'end' => [
                    'galaxy' => $fleet['fleet_end_galaxy'],
                    'system' => $fleet['fleet_end_system'],
                    'planet' => $fleet['fleet_end_planet'],
                    'type' => $fleet['fleet_end_type'],
                ],
            ],
        ]);
    }

    /**
     * Send a delivery message to the fleet owner
     *
     * @param array<string, mixed> $fleet
     * @param array<string, mixed> $tradingPlanets
     */
    private function sendDeliveryMessageToOwner(array $fleet, array $tradingPlanets): void
    {
        // send message
        Functions::sendMessage(
            self::int($tradingPlanets['start_id']),
            0,
            self::int($fleet['fleet_start_time']),
            5,
            __('game/missions.mi_fleet_command'),
            __('game/transport.tra_reaching'),
            StringsHelper::parseReplacements(__('game/transport.tra_delivered_resources'), [
                self::str($tradingPlanets['start_name']),
                Fleets::startLink($fleet, ''),
                self::str($tradingPlanets['target_name']),
                Fleets::targetLink($fleet, ''),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_metal'])),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_crystal'])),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_deuterium'])),
            ])
        );
    }

    /**
     * Send a delivery message to the receiver, only if the target planet is not a planet from the same user
     *
     * @param array<string, mixed> $fleet
     * @param array<string, mixed> $tradingPlanets
     */
    private function sendDeliveryMessageToReceiver(array $fleet, array $tradingPlanets): void
    {
        if ($tradingPlanets['start_id'] != $tradingPlanets['target_id']) {
            // send message
            Functions::sendMessage(
                self::int($tradingPlanets['target_id']),
                0,
                self::int($fleet['fleet_start_time']),
                5,
                __('game/transport.tra_incoming_from'),
                __('game/transport.tra_incoming_title'),
                StringsHelper::parseReplacements(__('game/transport.tra_incoming_delivery'), [
                    self::str($tradingPlanets['start_user_name']),
                    self::str($tradingPlanets['start_name']),
                    Fleets::startLink($fleet, ''),
                    self::str($tradingPlanets['target_name']),
                    Fleets::targetLink($fleet, ''),
                    $this->formatService->prettyNumber(self::int($fleet['fleet_resource_metal'])),
                    $this->formatService->prettyNumber(self::int($fleet['fleet_resource_crystal'])),
                    $this->formatService->prettyNumber(self::int($fleet['fleet_resource_deuterium'])),
                    $this->formatService->prettyNumber(self::int($tradingPlanets['target_metal'])),
                    $this->formatService->prettyNumber(self::int($tradingPlanets['target_crystal'])),
                    $this->formatService->prettyNumber(self::int($tradingPlanets['target_deuterium'])),
                    $this->formatService->prettyNumber(self::num($tradingPlanets['target_metal']) + self::num($fleet['fleet_resource_metal'])),
                    $this->formatService->prettyNumber(self::num($tradingPlanets['target_crystal']) + self::num($fleet['fleet_resource_crystal'])),
                    $this->formatService->prettyNumber(self::num($tradingPlanets['target_deuterium']) + self::num($fleet['fleet_resource_deuterium'])),
                ])
            );
        }
    }

    /**
     * Send a message informing that the fleet is back
     *
     * @param array<string, mixed> $fleet
     * @param array<string, mixed> $tradingPlanets
     */
    private function sendReturnMessage(array $fleet, array $tradingPlanets): void
    {
        $text = __('game/missions.mi_fleet_back_without_resources');
        $replacements = [
            $tradingPlanets['target_name'],
            Fleets::targetLink($fleet, ''),
            $tradingPlanets['start_name'],
            Fleets::startLink($fleet, ''),
        ];

        if (Fleets::hasResources($fleet)) {
            $text = __('game/missions.mi_fleet_back_with_resources');
            $replacements = [
                self::str($fleet['planet_end_name']),
                Fleets::targetLink($fleet, ''),
                self::str($fleet['planet_start_name']),
                Fleets::startLink($fleet, ''),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_metal'])),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_crystal'])),
                $this->formatService->prettyNumber(self::int($fleet['fleet_resource_deuterium'])),
            ];
        }

        // send message
        Functions::sendMessage(
            self::int($tradingPlanets['start_id']),
            0,
            self::int($fleet['fleet_end_time']),
            5,
            __('game/missions.mi_fleet_command'),
            __('game/missions.mi_fleet_back_title'),
            StringsHelper::parseReplacements($text, $replacements)
        );
    }
}
