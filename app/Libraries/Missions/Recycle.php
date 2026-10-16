<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Services\Game\FleetEventsService;
use App\Services\Game\PlayerClassService;
use App\Support\FleetShips;
use App\Services\Game\Formulas\FleetsService;
use App\Services\FormatService;
use App\Libraries\Functions;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Recycle extends Missions
{
    /**
     * Contains the target planet debris amount
     *
     * @var array{metal: int|float, crystal: int|float}
     */
    private array $planetDebris = [
        'metal' => 0,
        'crystal' => 0,
    ];

    /**
     * Contains the maximum capacity of the recyclers
     */
    private int | float $recyclersCapacity = 0;

    public function __construct(private FormatService $formatService)
    {
        parent::__construct();
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    public function recycleMission(array $fleetRow): void
    {
        $recycledResources = $this->calculateCapacity($fleetRow);

        if ($fleetRow['fleet_mess'] == 0 && $fleetRow['fleet_start_time'] <= time()) {
            $this->updatePlanetDebrisFieldAndFleet([
                'recycled' => [
                    'metal' => $recycledResources['metal'],
                    'crystal' => $recycledResources['crystal'],
                ],
                'coords' => [
                    'galaxy' => $fleetRow['fleet_end_galaxy'],
                    'system' => $fleetRow['fleet_end_system'],
                    'planet' => $fleetRow['fleet_end_planet'],
                ],
                'fleet_id' => $fleetRow['fleet_id'],
            ]);

            $message = sprintf(
                __('game/recycle.rec_result'),
                $this->formatService->prettyNumber(self::int($fleetRow['fleet_amount'])),
                $this->formatService->prettyNumber((int) $this->recyclersCapacity),
                app(FleetEventsService::class)->targetLink($fleetRow, ''),
                $this->formatService->prettyNumber((int) $this->planetDebris['metal']),
                $this->formatService->prettyNumber((int) $this->planetDebris['crystal']),
                $this->formatService->prettyNumber((int) $recycledResources['metal']),
                $this->formatService->prettyNumber((int) $recycledResources['crystal'])
            );

            $this->recycleMessage(
                self::int($fleetRow['fleet_owner']),
                $message,
                self::int($fleetRow['fleet_start_time']),
                sprintf(__('game/recycle.rec_report_title'), app(FleetEventsService::class)->targetLink($fleetRow, ''))
            );

            return;
        }

        if ($fleetRow['fleet_end_time'] <= time()) {
            $message = sprintf(
                __('game/missions.mi_fleet_back_with_resources'),
                self::str($fleetRow['planet_end_name']),
                app(FleetEventsService::class)->targetLink($fleetRow, ''),
                self::str($fleetRow['planet_start_name']),
                app(FleetEventsService::class)->startLink($fleetRow, ''),
                $this->formatService->prettyNumber(self::int($fleetRow['fleet_resource_metal'])),
                $this->formatService->prettyNumber(self::int($fleetRow['fleet_resource_crystal'])),
                $this->formatService->prettyNumber(self::int($fleetRow['fleet_resource_deuterium'])),
            );

            $this->recycleMessage(
                self::int($fleetRow['fleet_owner']),
                $message,
                self::int($fleetRow['fleet_end_time']),
                __('game/missions.mi_fleet_back_title')
            );

            parent::restoreFleet($fleetRow);
            parent::removeFleet(self::int($fleetRow['fleet_id']));
        }
    }

    /**
     * @param array<string, mixed> $fleetRow
     *
     * @return array{metal: int|float, crystal: int|float}
     */
    private function calculateCapacity(array $fleetRow): array
    {
        $targetPlanet = $this->getPlanetDebris([
            'coords' => [
                'galaxy' => $fleetRow['fleet_end_galaxy'],
                'system' => $fleetRow['fleet_end_system'],
                'planet' => $fleetRow['fleet_end_planet'],
            ],
        ]);

        $this->planetDebris = [
            'metal' => self::num($targetPlanet['planet_debris_metal']),
            'crystal' => self::num($targetPlanet['planet_debris_crystal']),
        ];

        // SOME REQUIRED VALUES
        $ships = FleetShips::decode(self::str($fleetRow['fleet_array']));
        $recycleCapacity = 0;
        $otherCapacity = 0;
        $currentResources = self::num($fleetRow['fleet_resource_metal']) +
            self::num($fleetRow['fleet_resource_crystal']) + self::num($fleetRow['fleet_resource_deuterium']);

        // CALCULATE STORAGE FOR EACH KIND OF SHIP
        foreach ($ships as $id => $amount) {
            $shipStorage = app(FleetsService::class)->getMaxStorage(
                self::int($this->pricelist[$id]['capacity']),
                self::int($fleetRow['research_hyperspace_technology']),
                (int) $id,
                app(PlayerClassService::class)->bonusesOf(self::int($fleetRow['fleet_owner']))
            );

            if ($id == 209) {
                $recycleCapacity += $shipStorage * $amount;
                continue;
            }

            $otherCapacity += $shipStorage * $amount;
        }

        if ($currentResources > $otherCapacity) {
            $recycleCapacity -= ($currentResources - $otherCapacity);
        }

        $this->recyclersCapacity = $recycleCapacity;

        return self::splitRecycledDebris($this->planetDebris['metal'], $this->planetDebris['crystal'], $recycleCapacity);
    }

    /**
     * How much metal and crystal the recyclers collect from a debris field.
     *
     * @return array{metal: int|float, crystal: int|float}
     */
    private static function splitRecycledDebris(int | float $metal, int | float $crystal, int | float $capacity): array
    {
        // everything fits into the recyclers
        if ($metal + $crystal <= $capacity) {
            return ['metal' => $metal, 'crystal' => $crystal];
        }

        // both resources exceed half of the capacity: split it evenly
        if ($metal > $capacity / 2 && $crystal > $capacity / 2) {
            return ['metal' => $capacity / 2, 'crystal' => $capacity / 2];
        }

        // take all of the smaller resource and fill the rest with the bigger one
        if ($metal > $crystal) {
            return ['metal' => min($metal, $capacity - $crystal), 'crystal' => $crystal];
        }

        return ['metal' => $metal, 'crystal' => min($crystal, $capacity - $metal)];
    }

    private function recycleMessage(int $owner, string $message, int $time, string $statusMessage): void
    {
        Functions::sendMessage(
            $owner,
            0,
            $time,
            5,
            __('game/recycle.rec_report_from'),
            $statusMessage,
            $message
        );
    }
}
