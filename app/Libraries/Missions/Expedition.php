<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Support\FleetShips;
use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Ship;
use App\Models\UsersStatistics;
use App\Services\FormatService;
use App\Services\Game\MerchantService;
use App\Services\Game\Formulas\ExpeditionService;
use App\Services\Game\Formulas\FleetsService;
use App\Libraries\Functions;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Expedition extends Missions
{
    private int $resourceExpeditionPoints = 0;
    private int $shipExpeditionPoints = 0;
    private int $fleetCapacity = 0;

    public function __construct(
        private ExpeditionService $expeditionService,
        private FormatService $formatService,
        private GameObjectRegistry $registry
    ) {
        parent::__construct();
    }

    /**
     * @param array<string, mixed> $fleet
     */
    public function expeditionMission(array $fleet): void
    {
        if (parent::canStartMission($fleet)) {
            $this->setExpeditionPoints($fleet);

            match ($this->expeditionService->getExpeditionResult()) {
                'darkMatter' => $this->resultDarkMatter($fleet),
                'ships' => $this->resultShips($fleet),
                'resources' => $this->resultResources($fleet),
                'delay' => $this->resultDelay($fleet),
                'early' => $this->resultEarly($fleet),
                'blackHole' => $this->resultBlackHole($fleet),
                'merchant' => $this->resultMerchant($fleet),
                'pirates' => $this->resultEnemy($fleet, 'pirates'),
                'aliens' => $this->resultEnemy($fleet, 'aliens'),
                default => $this->resultNothing($fleet),
            };

            return;
        }

        if (parent::canCompleteMission($fleet)) {
            $this->completeExpedition($fleet);
        }
    }

    /**
     * The fleet is back home: report and unload it.
     *
     * @param array<string, mixed> $fleet
     */
    private function completeExpedition(array $fleet): void
    {
        $fleetUsedStorage = self::num($fleet['fleet_resource_metal']) + self::num($fleet['fleet_resource_crystal']) + self::num($fleet['fleet_resource_deuterium']);
        $replacements = [
            self::str($fleet['planet_end_name']),
            $this->formatService->prettyCoords(self::int($fleet['fleet_end_galaxy']), self::int($fleet['fleet_end_system']), self::int($fleet['fleet_end_planet'])),
            self::str($fleet['planet_start_name']),
            $this->formatService->prettyCoords(self::int($fleet['fleet_start_galaxy']), self::int($fleet['fleet_start_system']), self::int($fleet['fleet_start_planet'])),
        ];

        $message = $fleetUsedStorage === 0
            ? sprintf(__('game/missions.mi_fleet_back_without_resources'), ...$replacements)
            : sprintf(
                __('game/missions.mi_fleet_back_with_resources'),
                ...$replacements,
                ...[
                    $this->formatService->prettyNumber(self::int($fleet['fleet_resource_metal'])),
                    $this->formatService->prettyNumber(self::int($fleet['fleet_resource_crystal'])),
                    $this->formatService->prettyNumber(self::int($fleet['fleet_resource_deuterium'])),
                ]
            );

        $this->expeditionMessage($fleet, $message);

        parent::restoreFleet($fleet);
        parent::removeFleet(self::int($fleet['fleet_id']));
    }

    /**
     * @param array<string, mixed> $fleet
     */
    private function setExpeditionPoints(array $fleet): void
    {
        $expeditionPoints = 0;

        foreach (FleetShips::decode(self::str($fleet['fleet_array'])) as $id => $count) {
            $ship = $this->registry->ships()->get($id);

            if (!$ship instanceof Ship) {
                continue;
            }

            $price = $ship->getPrice();

            if (in_array($id, $this->expeditionService->getPossibleShips(), true)) {
                $expeditionPoints += $this->expeditionService->calculateExpeditionPoints(
                    $price->getMetal() + $price->getCrystal()
                ) * $count;
            }

            $this->fleetCapacity += app(FleetsService::class)->getMaxStorage(
                $ship->getCapacity(),
                self::int($fleet['research_hyperspace_technology'])
            ) * $count;
        }

        $topPlayerPoints = (float) self::num(UsersStatistics::max('user_statistic_total_points'));

        $maxResourcePoints = $this->expeditionService->getMaxExpeditionPoints($topPlayerPoints);
        $maxShipsPoints = $this->expeditionService->getMaxShipsExpeditionPoints($topPlayerPoints);

        // limit the amount of resources and of ships that can be found
        $this->resourceExpeditionPoints = min($expeditionPoints, $maxResourcePoints);
        $this->shipExpeditionPoints = min($expeditionPoints, $maxShipsPoints);
    }

    /**
     * @todo needs polishing, there are 3 types of packages
     * small package: 300-400 DM
     * medium package: 500-700 DM
     * large package: 1.000-1.800 DM
     *
     * needs review because I replicated previous used logic for resources
     * I couldn't find any rule behind this...
     *
     * @param array<string, mixed> $fleet
     */
    private function resultDarkMatter(array $fleet): void
    {
        $darkMatterFound = $this->expeditionService->getDarkMatterSourceSize(
            $this->expeditionService->calculateDarkMatterSourceSize()
        );

        $this->expeditionMessage($fleet, sprintf(
            self::str(__('game/expedition.exp_dm_' . mt_rand(1, 5))),
            $this->formatService->prettyNumber($darkMatterFound),
            self::str(__('game/global.darkmatter'))
        ));

        $this->updateDarkMatter(self::int($fleet['fleet_owner']), $darkMatterFound);

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * @todo probably not 100% like the original game
     *
     * @param array<string, mixed> $fleet
     */
    private function resultShips(array $fleet): void
    {
        $shipsRatio = $this->expeditionService->getShipsObtainableChances();
        $foundChance = $this->shipExpeditionPoints / self::num($fleet['fleet_amount']);
        $currentFleet = FleetShips::decode(self::str($fleet['fleet_array']));
        $foundShip = [];

        for ($ship = 202; $ship <= 215; $ship++) {
            if (isset($currentFleet[$ship]) && $currentFleet[$ship] != 0) {
                $foundShip[$ship] = round($currentFleet[$ship] * self::num($shipsRatio[$ship]) * $foundChance) + 1;

                if ($foundShip[$ship] > 0) {
                    $currentFleet[$ship] += $foundShip[$ship];
                }
            }
        }

        $newShips = [];
        $foundShipMessage = '';

        foreach ($currentFleet as $ship => $count) {
            if ($count > 0) {
                $newShips[$ship] = $count;
            }
        }

        foreach ($foundShip as $ship => $count) {
            if ($count != 0) {
                $foundShipMessage .= self::str(__('game/ships.' . $this->resource[$ship])) . ': ' . $count . '<br>';
            }
        }

        $this->updateFleetArrayById([
            'ships' => FleetShips::encode($newShips),
            'amount' => array_sum($newShips),
            'fleet_id' => $fleet['fleet_id'],
        ]);

        $message = sprintf(
            self::str(__('game/expedition.exp_new_ships_' . mt_rand(1, 5))),
            $foundShipMessage
        );

        $this->expeditionMessage($fleet, $message);
    }

    /**
     * @param array<string, mixed> $fleet
     */
    private function resultResources(array $fleet): void
    {
        // fleet capacity
        $fleetUsedStorage = self::int($fleet['fleet_resource_metal']) + self::int($fleet['fleet_resource_crystal']) + self::int($fleet['fleet_resource_deuterium']);
        $fleetMaxCapacity = $this->fleetCapacity - $fleetUsedStorage;

        // expedition resources obtained calculations
        $typeObtained = $this->expeditionService->calculateResourceTypeObtained();
        $foundAmount = $this->expeditionService->getResourceFoundAmount(
            $this->expeditionService->getResourceSourceSizeMultChances(
                $typeObtained
            ),
            $this->resourceExpeditionPoints,
            $typeObtained
        );

        $loaded = min($foundAmount, $fleetMaxCapacity);

        $this->updateFleetResourcesById(self::int($fleet['fleet_id']), $typeObtained, $loaded);

        $this->expeditionMessage($fleet, sprintf(
            self::str(__('game/expedition.exp_new_resources_' . mt_rand(1, 4))),
            $this->formatService->prettyNumber($loaded),
            self::str(__('game/global.' . $typeObtained))
        ));

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * @todo probably not 100% like the original game
     *
     * @param array<string, mixed> $fleet
     */
    private function resultDelay(array $fleet): void
    {
        $fleetDelayMultiplier = $this->expeditionService->getFleetDeplay();
        $returnTime = self::int($fleet['fleet_end_time']) - self::int($fleet['fleet_end_stay']);

        $this->updateFleetEndTime(
            self::int($fleet['fleet_id']),
            (self::int($fleet['fleet_end_time']) + ($returnTime * $fleetDelayMultiplier))
        );

        $this->expeditionMessage($fleet, self::str(__('game/expedition.exp_delay_' . mt_rand(1, 5))));

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * @todo probably not 100% like the original game
     *
     * @param array<string, mixed> $fleet
     */
    private function resultEarly(array $fleet): void
    {
        $this->updateFleetEndTime(
            self::int($fleet['fleet_id']),
            self::earlyReturnEndTime(self::int($fleet['fleet_end_time']), self::int($fleet['fleet_end_stay']))
        );

        // bugfix: the messages are exp_faster_1/2, the former exp_early_1..5 keys do not exist
        $this->expeditionMessage($fleet, self::str(__('game/expedition.exp_faster_' . mt_rand(1, 2))));

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * The fleet comes back after half of its normal return time.
     *
     * Bugfix: this used `$returnTime / 2`, which is a float for odd return
     * times and made updateFleetEndTime(int) throw a TypeError.
     */
    private static function earlyReturnEndTime(int $endTime, int $endStay): int
    {
        return $endTime - intdiv($endTime - $endStay, 2);
    }

    /**
     * The fleet is lost completely (25 %) or loses 1 %, 34 % or 67 % of each
     * ship type; the rest returns home.
     *
     * @param array<string, mixed> $fleet
     */
    private function resultBlackHole(array $fleet): void
    {
        $lostChances = (mt_rand(0, 3) * 33 + 1) / 100;
        $ships = FleetShips::decode(self::str($fleet['fleet_array']));

        if ($lostChances == 1) {
            $this->expeditionMessage($fleet, self::str(__('game/expedition.exp_lost_1')));
            $this->loseFleet($fleet, $ships);

            return;
        }

        $losses = self::blackHoleLosses($ships, $lostChances);

        // too few ships of each type to lose even one
        if ($losses['lost'] === []) {
            $this->resultNothing($fleet);

            return;
        }

        $lostShips = '';
        foreach ($losses['lost'] as $ship => $count) {
            $lostShips .= self::str(__('game/ships.' . $this->resource[$ship])) . ': ' . $count . '<br>';
        }

        $this->expeditionMessage($fleet, sprintf(self::str(__('game/expedition.exp_lost_partial')), $lostShips));

        $this->updateLostShipsAndDefensePoints(self::int($fleet['fleet_owner']), $losses['lost']);
        $this->updateFleetArrayById([
            'ships' => FleetShips::encode($losses['remaining']),
            'amount' => array_sum($losses['remaining']),
            'fleet_id' => $fleet['fleet_id'],
        ]);
    }

    /**
     * Ships lost to a black hole and the ships left, by ship id.
     *
     * Bugfix: the original kept only the ship types that lost units (the
     * others vanished from the fleet), and when no type lost a unit it
     * destroyed the whole fleet.
     *
     * @param array<int, int> $ships
     *
     * @return array{lost: array<int, int>, remaining: array<int, int>}
     */
    private static function blackHoleLosses(array $ships, float $lostChances): array
    {
        $lost = [];
        $remaining = [];

        foreach ($ships as $ship => $amount) {
            $lostAmount = (int) floor($amount * $lostChances);

            if ($lostAmount > 0) {
                $lost[$ship] = $lostAmount;
            }

            if ($amount > $lostAmount) {
                $remaining[$ship] = $amount - $lostAmount;
            }
        }

        return ['lost' => $lost, 'remaining' => $remaining];
    }

    /**
     * @param array<string, mixed> $fleet
     * @param array<int, int>      $ships
     */
    private function loseFleet(array $fleet, array $ships): void
    {
        $this->updateLostShipsAndDefensePoints(self::int($fleet['fleet_owner']), $ships);
        parent::removeFleet(self::int($fleet['fleet_id']));
    }

    /**
     * Pirates or aliens attack the expedition (ExpeditionBattle): the report
     * of the expedition, then the combat report; survivors fly home.
     *
     * @param array<string, mixed> $fleet
     */
    private function resultEnemy(array $fleet, string $enemy): void
    {
        $texts = $enemy === 'pirates'
            ? ['primitive_enemy_1', 'primitive_enemy_2', 'primitive_enemy_3', 'primitive_enemy_4', 'primitive_enemy_5', 'primitive_enemy_6', 'primitive_enemy_8']
            : ['advanced_enemy_1', 'advanced_enemy_2'];

        $this->expeditionMessage($fleet, self::str(__('game/expedition.exp_' . $texts[array_rand($texts)])));

        app(ExpeditionBattle::class)->fight($fleet, $enemy);
    }

    /**
     * A friendly race sends a merchant: the player's next call of the
     * merchant (trader, exchange resources) is free.
     *
     * @param array<string, mixed> $fleet
     */
    private function resultMerchant(array $fleet): void
    {
        app(MerchantService::class)->grantFreeCall(self::int($fleet['fleet_owner']));

        $this->expeditionMessage($fleet, self::str(__('game/expedition.exp_trader')));

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * @param array<string, mixed> $fleet
     */
    private function resultNothing(array $fleet): void
    {
        $this->expeditionMessage($fleet, self::str(__('game/expedition.exp_nothing_' . mt_rand(1, 6))));

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * Send an expedition report to the fleet owner, dated to the end of the stay.
     *
     * @param array<string, mixed> $fleet
     */
    private function expeditionMessage(array $fleet, string $message): void
    {
        $subject = sprintf(
            __('game/expedition.exp_report_title'),
            $this->formatService->prettyCoords(self::int($fleet['fleet_end_galaxy']), self::int($fleet['fleet_end_system']), self::int($fleet['fleet_end_planet']))
        );

        Functions::sendMessage(
            self::int($fleet['fleet_owner']),
            0,
            self::int($fleet['fleet_end_stay']),
            5,
            __('game/missions.mi_fleet_command'),
            $subject,
            $message
        );
    }
}
