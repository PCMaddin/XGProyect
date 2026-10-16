<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Support\FleetShips;
use App\Services\Game\PlayerClassService;
use App\Enums\PlayerClass;
use App\Support\ClassBonuses;
use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Ship;
use App\Models\UsersStatistics;
use App\Services\FormatService;
use App\Services\Game\ExpeditionAreaService;
use App\Services\Game\Items\ItemCatalog;
use App\Services\Game\Items\ItemService;
use App\Services\Game\Items\ItemTexts;
use App\Services\Game\MerchantService;
use App\Services\Game\Formulas\ExpeditionService;
use App\Services\Game\Formulas\FleetsService;
use App\Libraries\Functions;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class Expedition extends Missions
{
    private int $resourceExpeditionPoints = 0;
    private int $shipExpeditionPoints = 0;
    private int $fleetCapacity = 0;

    /** logbook entry of a probe, added to the next report */
    private string $logbook = '';

    /** the class of the fleet's owner */
    private PlayerClass $owner = PlayerClass::None;

    /** the bonuses of the owner's class and alliance class */
    private ClassBonuses $bonuses;

    /** the espionage probe, which reports how busy the area is */
    private const PROBE = 210;

    public function __construct(
        private ExpeditionService $expeditionService,
        private FormatService $formatService,
        private GameObjectRegistry $registry,
        private ExpeditionAreaService $areas,
    ) {
        parent::__construct();
        $this->bonuses = new ClassBonuses();
    }

    /**
     * @param array<string, mixed> $fleet
     */
    public function expeditionMission(array $fleet): void
    {
        if (parent::canStartMission($fleet)) {
            $this->bonuses = app(PlayerClassService::class)->bonusesOf(self::int($fleet['fleet_owner']));
            $this->owner = $this->bonuses->player;
            $this->setExpeditionPoints($fleet);

            match ($this->result($fleet)) {
                'darkMatter' => $this->resultDarkMatter($fleet),
                'ships' => $this->resultShips($fleet),
                'resources' => $this->resultResources($fleet),
                'delay' => $this->resultDelay($fleet),
                'early' => $this->resultEarly($fleet),
                'blackHole' => $this->resultBlackHole($fleet),
                'merchant' => $this->resultMerchant($fleet),
                'item' => $this->resultItem($fleet),
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
     * The result of the expedition. In a busy area it more often finds
     * nothing (ExpeditionAreaService); a probe in the fleet reports how busy
     * the area is, except when the fleet is lost.
     *
     * @param array<string, mixed> $fleet
     */
    private function result(array $fleet): string
    {
        $load = $this->areas->visit(
            self::int($fleet['fleet_end_galaxy']),
            self::int($fleet['fleet_end_system']),
            self::int($fleet['fleet_end_stay'])
        );
        $result = $this->expeditionService->getExpeditionResult();

        if (mt_rand() / mt_getrandmax() < $this->areas->nothingChance($load, self::holdingTime($fleet))) {
            $result = 'nothing';
        }

        // discoverers meet pirates and aliens half as often
        if (in_array($result, ['pirates', 'aliens'], true) && mt_rand() / mt_getrandmax() >= $this->owner->expeditionEnemyFactor()) {
            $result = 'nothing';
        }

        $withProbe = (FleetShips::decode(self::str($fleet['fleet_array']))[self::PROBE] ?? 0) > 0;
        $this->logbook = $withProbe && $result !== 'blackHole'
            ? self::str(__('game/expedition.' . $this->areas->logbookKey($load)))
            : '';

        return $result;
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

            if ($this->expeditionService->countsForExpeditionPoints($id)) {
                $expeditionPoints += $this->expeditionService->calculateExpeditionPoints(
                    $price->getMetal() + $price->getCrystal()
                ) * $count;
            }

            $this->fleetCapacity += app(FleetsService::class)->getMaxStorage(
                $ship->getCapacity(),
                self::int($fleet['research_hyperspace_technology']),
                $id,
                $this->bonuses
            ) * $count;
        }

        $topPlayerPoints = (float) self::num(UsersStatistics::max('user_statistic_total_points'));

        // limit the amount of resources and of ships that can be found
        $this->resourceExpeditionPoints = $this->expeditionService->getResourceFindPoints($expeditionPoints, $topPlayerPoints);
        $this->shipExpeditionPoints = min($expeditionPoints, $this->expeditionService->getMaxShipFindPoints($topPlayerPoints));
    }

    /**
     * Dark matter in one of three sizes (weights in the settings): small
     * 300-400, medium 500-700, large 1.000-1.800.
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
     * The fleet finds ships: a factor (normal, large or huge find) times its
     * expedition points (at most the cap for ship finds) in structure points
     * (metal and crystal), see ExpeditionService::getFoundShips; with none
     * found it is a "nothing".
     *
     * @param array<string, mixed> $fleet
     */
    private function resultShips(array $fleet): void
    {
        $currentFleet = FleetShips::decode(self::str($fleet['fleet_array']));
        $budget = $this->expeditionService->calculateShipFoundAmount(
            $this->expeditionService->getShipSourceSizeMultChances($this->expeditionService->calculateResourceSourceSize()),
            $this->shipExpeditionPoints
        );
        $found = $this->expeditionService->getFoundShips($currentFleet, (int) ($budget * $this->owner->expeditionFindFactor()), $this->shipStructures());

        if ($found === []) {
            $this->resultNothing($fleet);

            return;
        }

        $foundShipMessage = '';

        foreach ($found as $ship => $count) {
            $currentFleet[$ship] = ($currentFleet[$ship] ?? 0) + $count;
            $foundShipMessage .= self::str(__('game/ships.' . $this->resource[$ship])) . ': ' . $count . '<br>';
        }

        $this->updateFleetArrayById([
            'ships' => FleetShips::encode($currentFleet),
            'amount' => array_sum($currentFleet),
            'fleet_id' => $fleet['fleet_id'],
        ]);

        $this->expeditionMessage($fleet, sprintf(
            self::str(__('game/expedition.exp_new_ships_' . mt_rand(1, 7))),
            $foundShipMessage
        ));
    }

    /**
     * Metal and crystal of each ship, by id.
     *
     * @return array<int, int>
     */
    private function shipStructures(): array
    {
        return $this->registry->ships()
            ->mapWithKeys(fn (Ship $ship): array => [$ship->getId() => $ship->getPrice()->getMetal() + $ship->getPrice()->getCrystal()])
            ->all();
    }

    /**
     * One resource (frequency 3:2:1 by default): a factor (normal, large or
     * huge find) times the expedition points, halved for crystal and divided
     * by three for deuterium; the fleet loads what fits into its effective
     * capacity (the free capacity less half of the fuel).
     *
     * @param array<string, mixed> $fleet
     */
    private function resultResources(array $fleet): void
    {
        // fleet capacity
        $fleetUsedStorage = self::int($fleet['fleet_resource_metal']) + self::int($fleet['fleet_resource_crystal']) + self::int($fleet['fleet_resource_deuterium']);
        $fleetMaxCapacity = max(0, $this->fleetCapacity - $fleetUsedStorage - intdiv(self::int($fleet['fleet_fuel'] ?? 0), 2));

        // expedition resources obtained calculations
        $typeObtained = $this->expeditionService->calculateResourceTypeObtained();
        // bugfix: this passed the resource type as the source size, so the
        // large and huge finds never happened
        $foundAmount = $this->expeditionService->getResourceFoundAmount(
            $this->expeditionService->getResourceSourceSizeMultChances(
                $this->expeditionService->calculateResourceSourceSize()
            ),
            $this->resourceExpeditionPoints,
            $typeObtained
        );

        $loaded = min((int) ($foundAmount * $this->owner->expeditionFindFactor()), $fleetMaxCapacity);

        $this->updateFleetResourcesById(self::int($fleet['fleet_id']), $typeObtained, $loaded);

        $this->expeditionMessage($fleet, sprintf(
            self::str(__('game/expedition.exp_new_resources_' . mt_rand(1, 4))),
            $this->formatService->prettyNumber($loaded),
            self::str(__('game/global.' . $typeObtained))
        ));

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * The return flight is extended by 2, 3 or 5 times the holding time
     * (weights in the settings).
     *
     * Fix: this extended it by a multiple of the return flight, not of the
     * holding time as in the original.
     *
     * @param array<string, mixed> $fleet
     */
    private function resultDelay(array $fleet): void
    {
        $this->updateFleetEndTime(
            self::int($fleet['fleet_id']),
            self::int($fleet['fleet_end_time']) + self::holdingTime($fleet) * $this->expeditionService->getFleetDeplay()
        );

        $this->expeditionMessage($fleet, self::str(__('game/expedition.exp_delay_' . mt_rand(1, 5))));

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * The return flight is shortened by a third of the holding time times
     * 2, 3 or 5 (the weights of the delay), but takes at least a second.
     *
     * Fix: this halved the return flight instead.
     *
     * @param array<string, mixed> $fleet
     */
    private function resultEarly(array $fleet): void
    {
        $this->updateFleetEndTime(
            self::int($fleet['fleet_id']),
            self::earlyReturnEndTime(
                self::int($fleet['fleet_end_time']),
                self::int($fleet['fleet_end_stay']),
                self::holdingTime($fleet),
                $this->expeditionService->getFleetDeplay()
            )
        );

        // bugfix: the messages are exp_faster_1/2, the former exp_early_1..5 keys do not exist
        $this->expeditionMessage($fleet, self::str(__('game/expedition.exp_faster_' . mt_rand(1, 2))));

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * The end of the return flight shortened by a third of the holding time
     * times the factor; the flight takes at least a second.
     */
    private static function earlyReturnEndTime(int $endTime, int $endStay, int $holdingTime, int $factor): int
    {
        return max($endStay + 1, $endTime - intdiv($holdingTime * $factor, 3));
    }

    /**
     * How long the fleet held at the expedition position, in seconds.
     *
     * @param array<string, mixed> $fleet
     */
    private static function holdingTime(array $fleet): int
    {
        return max(0, self::int($fleet['fleet_end_stay']) - self::int($fleet['fleet_start_time']));
    }

    /**
     * The fleet is lost completely, as in the original (the partial losses
     * of 1, 34 or 67 % were an addition of this game).
     *
     * @param array<string, mixed> $fleet
     */
    private function resultBlackHole(array $fleet): void
    {
        $this->expeditionMessage($fleet, self::str(__('game/expedition.exp_lost_1')));
        $this->loseFleet($fleet, FleetShips::decode(self::str($fleet['fleet_array'])));
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
     * The expedition meets a merchant: the player gets his offer (trader,
     * exchange resources) for free, or a better rate for the current one.
     *
     * @param array<string, mixed> $fleet
     */
    private function resultMerchant(array $fleet): void
    {
        app(MerchantService::class)->grantExpeditionOffer(self::int($fleet['fleet_owner']));

        $this->expeditionMessage($fleet, self::str(__('game/expedition.exp_trader')));

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * The fleet finds an item (no field items): bronze in a normal find,
     * silver in a large one, two gold items in a huge one (sizes as for
     * resources).
     *
     * @param array<string, mixed> $fleet
     */
    private function resultItem(array $fleet): void
    {
        $size = $this->expeditionService->calculateResourceSourceSize();
        $tier = ['large' => 'silver', 'xl' => 'gold'][$size] ?? 'bronze';
        $candidates = array_keys(app(ItemCatalog::class)->tradable($tier));
        $keys = array_map(fn (): string => $candidates[array_rand($candidates)], range(1, $size === 'xl' ? 2 : 1));
        $found = [];

        foreach ($keys as $key) {
            app(ItemService::class)->add(self::int($fleet['fleet_owner']), $key);
            $found[] = app(ItemTexts::class)->name($key);
        }

        $this->expeditionMessage($fleet, sprintf(self::str(__('game/expedition.exp_found_object_' . count($found))), ...$found));

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * @param array<string, mixed> $fleet
     */
    private function resultNothing(array $fleet): void
    {
        $this->expeditionMessage($fleet, self::str(__('game/expedition.exp_nothing_' . mt_rand(1, 16))));

        parent::returnFleet(self::int($fleet['fleet_id']));
    }

    /**
     * Send an expedition report to the fleet owner, dated to the end of the stay.
     *
     * @param array<string, mixed> $fleet
     */
    private function expeditionMessage(array $fleet, string $message): void
    {
        if ($this->logbook !== '') {
            $message .= '<br><br>' . $this->logbook;
            $this->logbook = '';
        }

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
