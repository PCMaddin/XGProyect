<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use Illuminate\Support\Facades\DB;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Services\Game\DeepSpaceDebrisService;
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
            $this->harvest($fleetRow, $recycledResources);

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
        $deepSpace = self::inDeepSpace($fleetRow);
        $this->planetDebris = $this->debrisAt($fleetRow, $deepSpace);

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

            // recyclers and pathfinders harvest, in deep space only pathfinders
            if ($id == Ships::SHIP_PATHFINDER || ($id == Ships::SHIP_RECYCLER && !$deepSpace)) {
                $recycleCapacity += $shipStorage * $amount;
                continue;
            }

            $otherCapacity += $shipStorage * $amount;
        }

        if ($currentResources > $otherCapacity) {
            $recycleCapacity -= ($currentResources - $otherCapacity);
        }

        $this->recyclersCapacity = $recycleCapacity;

        return DebrisLoad::split($this->planetDebris['metal'], $this->planetDebris['crystal'], $recycleCapacity);
    }

    /**
     * The debris field the fleet harvests.
     *
     * @param array<string, mixed> $fleetRow
     *
     * @return array{metal: int|float, crystal: int|float}
     */
    private function debrisAt(array $fleetRow, bool $deepSpace): array
    {
        if ($deepSpace) {
            return app(DeepSpaceDebrisService::class)->get(self::int($fleetRow['fleet_end_galaxy']), self::int($fleetRow['fleet_end_system']));
        }

        $targetPlanet = $this->getPlanetDebris([
            'coords' => [
                'galaxy' => $fleetRow['fleet_end_galaxy'],
                'system' => $fleetRow['fleet_end_system'],
                'planet' => $fleetRow['fleet_end_planet'],
            ],
        ]);

        return [
            'metal' => self::num($targetPlanet['planet_debris_metal'] ?? 0),
            'crystal' => self::num($targetPlanet['planet_debris_crystal'] ?? 0),
        ];
    }

    /**
     * Load the debris into the fleet and take it from the field.
     *
     * @param array<string, mixed>                       $fleetRow
     * @param array{metal: int|float, crystal: int|float} $recycled
     */
    private function harvest(array $fleetRow, array $recycled): void
    {
        if (self::inDeepSpace($fleetRow)) {
            $this->harvestDeepSpace($fleetRow, $recycled);

            return;
        }

        $this->updatePlanetDebrisFieldAndFleet([
            'recycled' => [
                'metal' => $recycled['metal'],
                'crystal' => $recycled['crystal'],
            ],
            'coords' => [
                'galaxy' => $fleetRow['fleet_end_galaxy'],
                'system' => $fleetRow['fleet_end_system'],
                'planet' => $fleetRow['fleet_end_planet'],
            ],
            'fleet_id' => $fleetRow['fleet_id'],
        ]);
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    private static function inDeepSpace(array $fleetRow): bool
    {
        return self::int($fleetRow['fleet_end_planet']) === DeepSpaceDebrisService::POSITION;
    }

    /**
     * The pathfinders load debris in deep space.
     *
     * @param array<string, mixed>                       $fleetRow
     * @param array{metal: int|float, crystal: int|float} $recycled
     */
    private function harvestDeepSpace(array $fleetRow, array $recycled): void
    {
        DB::transaction(function () use ($fleetRow, $recycled): void {
            app(DeepSpaceDebrisService::class)->take(
                self::int($fleetRow['fleet_end_galaxy']),
                self::int($fleetRow['fleet_end_system']),
                (float) $recycled['metal'],
                (float) $recycled['crystal']
            );

            DB::table('fleets')->where('fleet_id', self::int($fleetRow['fleet_id']))->update([
                'fleet_resource_metal' => DB::raw('`fleet_resource_metal` + ' . (float) $recycled['metal']),
                'fleet_resource_crystal' => DB::raw('`fleet_resource_crystal` + ' . (float) $recycled['crystal']),
                'fleet_mess' => 1,
            ]);
        });
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
