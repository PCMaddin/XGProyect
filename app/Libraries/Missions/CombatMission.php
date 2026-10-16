<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Enums\PlayerClass;
use App\Services\Game\WreckFieldService;
use App\Services\Game\FleetEventsService;
use App\Support\ClassBonuses;
use App\Support\FleetShips;
use App\Libraries\Functions;
use App\Libraries\UpdatesLibrary;
use App\Services\FormatService;
use App\Services\Game\Formulas\FleetsService;
use App\Libraries\BattleEngine\Core\Battle;
use App\Libraries\BattleEngine\Core\BattleReport;
use App\Libraries\BattleEngine\Models\PlayerGroup;

/**
 * The battle shared by the attack and the moon destruction missions: build
 * the forces, fight, then write losses, loot, debris and the combat report.
 *
 * @phpstan-type Loot array{metal: int|float, crystal: int|float, deuterium: int|float}
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
abstract class CombatMission extends Missions
{
    use BuildsCombatForces;
    use SendsCombatReports;
    /** the defender did not log in for a week: discoverers plunder more */
    private bool $defenderInactive = false;

    /** @var array{0: array<int, int>, 1: array<int, int>} the planet owner's ships before the battle and those lost */
    private array $planetLosses = [[], []];

    /** reapers collect up to a quarter of the debris field after the battle */
    private const REAPER_SHARE = 0.25;

    /** @var array<int, array{player: int, ships: array<int, int|float>, loot: int|float}> the attacking fleets flying home */
    private array $returningFleets = [];

    /** the report line on the debris the reapers collected */
    private string $reaperLine = '';

    /** @var list<array{origin: array{planet: int, user: int, back: int}|null, player: int, before: array<int, int>, lost: array<int, int>}> the attacking fleets' losses */
    private array $attackerLosses = [];

    protected const SHIP_MIN_ID = 202;
    protected const SHIP_MAX_ID = 219;
    protected const DEFENSE_MIN_ID = 401;
    protected const DEFENSE_MAX_ID = 408;

    /** the fleet id of the planet's own ships and defenses */
    protected const PLANET_FORCES = 0;

    public function __construct(protected FormatService $formatService)
    {
        parent::__construct();
    }

    /**
     * The title of the combat report message.
     */
    abstract protected function reportTitle(string $targetName, string $coords): string;

    /**
     * Mission specific consequences of the battle (moon creation or destruction).
     *
     * @param array<string, mixed> $fleetRow
     * @param array<string, mixed> $targetPlanet
     * @param array<string, mixed> $targetUser
     */
    abstract protected function afterBattle(array $fleetRow, BattleReport $report, array $targetPlanet, array $targetUser): void;

    /**
     * Called for every surviving attacking fleet when the attackers won;
     * returns the ships that fly home (and carry the loot).
     *
     * @param array<string, mixed>  $targetPlanet
     * @param array<int, int|float> $ships surviving ships by id
     *
     * @return array<int, int|float>
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function onAttackerVictory(int $playerId, array $targetPlanet, array $ships): array
    {
        return $ships;
    }

    /**
     * Text appended to the stored combat report.
     *
     * @param array<string, mixed> $fleetRow
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function reportAppendix(array $fleetRow, BattleReport $report): string
    {
        return $this->reaperLine;
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    protected function combatMission(array $fleetRow): void
    {
        if ($fleetRow['fleet_mess'] == 0 && $fleetRow['fleet_start_time'] <= time()) {
            $this->fight($fleetRow);

            return;
        }

        if ($this->canCompleteMission($fleetRow)) {
            $this->returnHome($fleetRow);
        }
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    private function fight(array $fleetRow): void
    {
        $targetPlanet = $this->getAllPlanetDataByCoords([
            'coords' => [
                'galaxy' => $fleetRow['fleet_end_galaxy'],
                'system' => $fleetRow['fleet_end_system'],
                'planet' => $fleetRow['fleet_end_planet'],
                'type' => $fleetRow['fleet_end_type'],
            ],
        ]);

        // bugfix: LIB_PATH was only defined by the legacy bootstrap, which no
        // longer runs, so every battle crashed with "Undefined constant"
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'BattleEngine' . DIRECTORY_SEPARATOR . 'Utils' . DIRECTORY_SEPARATOR . 'Includer.php';

        AttackLang::activate($this->resource);

        $this->releaseFleets($fleetRow);

        $targetUser = $this->getAllUserDataByUserId(self::int($targetPlanet['planet_user_id']));
        $lastOnline = self::int($targetUser['onlinetime'] ?? 0);
        $this->defenderInactive = $lastOnline > 0 && $lastOnline < time() - ONE_WEEK;

        UpdatesLibrary::updatePlanetResources($targetUser, $targetPlanet, time());

        $fleetGroup = self::int($fleetRow['fleet_group']);
        $attackers = $fleetGroup != 0
            ? $this->fleetsGroup($this->getAllAcsFleetsByGroupId($fleetGroup) ?? [])
            : $this->attackingFleet($fleetRow);

        $defenders = $this->fleetsGroup(
            $this->getAllFleetsByEndCoordsAndTimes([
                'coords' => [
                    'galaxy' => $fleetRow['fleet_end_galaxy'],
                    'system' => $fleetRow['fleet_end_system'],
                    'planet' => $fleetRow['fleet_end_planet'],
                    'type' => $fleetRow['fleet_end_type'],
                ],
                'time' => time(),
            ]),
            $targetUser
        );
        $this->addPlanetForces($defenders, $targetUser, $targetPlanet, $fleetRow);

        $battle = new Battle($attackers, $defenders);
        $battle->startBattle();

        $report = $battle->getReport();
        $this->settleBattle($fleetRow, $report, $targetPlanet);
        $this->afterBattle($fleetRow, $report, $targetPlanet, $targetUser);
        $this->createNewReportAndSendIt($fleetRow, $report, self::str($targetPlanet['planet_name']));
    }

    /**
     * The fleet (or the whole ACS group) is done with the target.
     *
     * @param array<string, mixed> $fleetRow
     */
    private function releaseFleets(array $fleetRow): void
    {
        if ($fleetRow['fleet_group'] > 0) {
            $this->deleteAcsFleetById($fleetRow['fleet_group']);
            $this->updateAcsFleetStatusByGroupId($fleetRow['fleet_group']);

            return;
        }

        parent::returnFleet(self::int($fleetRow['fleet_id']));
    }

    /**
     * Write the battle result: surviving fleets, loot, planet losses, points and debris.
     *
     * @param array<string, mixed> $fleetRow
     * @param array<string, mixed> $targetPlanet
     */
    private function settleBattle(array $fleetRow, BattleReport $report, array $targetPlanet): void
    {
        $afterBattleAttackers = $report->getAfterBattleAttackers();
        $steal = $this->updateAttackers(
            $report->getPresentationAttackersFleetOnRound('START'),
            $afterBattleAttackers,
            $targetPlanet
        );

        $report->setSteal($steal);

        $afterBattleDefenders = $report->getAfterBattleDefenders();
        $this->updateDefenders(
            $report->getPresentationDefendersFleetOnRound('START'),
            $afterBattleDefenders,
            $targetPlanet,
            $steal
        );

        $this->updatePoints($report, $afterBattleAttackers, $afterBattleDefenders);
        $this->updateDebris($fleetRow, $report);
        $this->reapersHarvest($fleetRow, $report);
        $this->leaveWrecks($fleetRow, $targetPlanet);
    }

    /**
     * Reapers collect up to a quarter of the new debris field right after
     * the battle, as far as their holds (and the fleet's free room after
     * the loot) allow (OGame 7).
     *
     * @param array<string, mixed> $fleetRow
     */
    private function reapersHarvest(array $fleetRow, BattleReport $report): void
    {
        [$metal, $crystal] = $report->getDebris();
        $left = ['metal' => $metal * self::REAPER_SHARE, 'crystal' => $crystal * self::REAPER_SHARE];
        $collected = ['metal' => 0, 'crystal' => 0];

        foreach ($this->returningFleets as $fleetId => $fleet) {
            $reapers = (int) ($fleet['ships'][Ships::SHIP_REAPER] ?? 0);

            if ($reapers === 0) {
                continue;
            }

            $room = min(
                $reapers * $this->shipCapacity(Ships::SHIP_REAPER, $fleet['player']),
                $this->fleetCapacity($fleet['ships'], $fleet['player']) - $fleet['loot']
            );
            $load = DebrisLoad::split(floor($left['metal']), floor($left['crystal']), floor($room));

            if ($load['metal'] + $load['crystal'] <= 0) {
                continue;
            }

            $left['metal'] -= $load['metal'];
            $left['crystal'] -= $load['crystal'];
            $collected['metal'] += $load['metal'];
            $collected['crystal'] += $load['crystal'];

            $this->updateReturningFleetData([
                'ships' => FleetShips::encode($fleet['ships']),
                'amount' => array_sum($fleet['ships']),
                'stolen' => ['metal' => $load['metal'], 'crystal' => $load['crystal'], 'deuterium' => 0],
                'fleet_id' => $fleetId,
            ]);
        }

        if ($collected['metal'] + $collected['crystal'] <= 0) {
            return;
        }

        $this->updatePlanetDebrisByCoords([
            'time' => time(),
            'debris' => ['metal' => -$collected['metal'], 'crystal' => -$collected['crystal']],
            'coords' => [
                'galaxy' => $fleetRow['fleet_end_galaxy'],
                'system' => $fleetRow['fleet_end_system'],
                'planet' => $fleetRow['fleet_end_planet'],
            ],
        ]);

        $this->reaperLine = sprintf(
            (string) __('game/attack.at_reapers_collected'),
            $this->formatService->prettyNumber((int) $collected['metal']),
            $this->formatService->prettyNumber((int) $collected['crystal'])
        );
    }

    private function shipCapacity(int $shipId, int $playerId): int
    {
        return app(FleetsService::class)->getMaxStorage(
            self::int($this->pricelist[$shipId]['capacity']),
            $this->hyperspaceTechnology[$playerId] ?? 0,
            $shipId,
            $this->playerClasses[$playerId] ?? new ClassBonuses()
        );
    }

    /**
     * @param array<int, int|float> $ships
     */
    private function fleetCapacity(array $ships, int $playerId): int | float
    {
        $capacity = 0;

        foreach ($ships as $shipId => $count) {
            $capacity += $count * $this->shipCapacity((int) $shipId, $playerId);
        }

        return $capacity;
    }

    /**
     * Wreck fields (OGame 7): for the defender at the planet, and for
     * attacking generals at their start planet.
     *
     * @param array<string, mixed> $fleetRow
     * @param array<string, mixed> $targetPlanet
     */
    private function leaveWrecks(array $fleetRow, array $targetPlanet): void
    {
        $wrecks = app(WreckFieldService::class);
        $battleTime = self::int($fleetRow['fleet_start_time'] ?? time());

        $wrecks->recordBattle(
            self::int($targetPlanet['planet_id']),
            self::int($targetPlanet['planet_user_id']),
            $this->planetLosses[0],
            $this->planetLosses[1],
            $battleTime
        );

        foreach ($this->attackerLosses as $losses) {
            $origin = $losses['origin'];

            if ($origin !== null) {
                // repairable once the fleet is back
                $wrecks->recordBattle($origin['planet'], $origin['user'], $losses['before'], $losses['lost'], $battleTime, $origin['back']);
            }
        }
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    private function returnHome(array $fleetRow): void
    {
        $message = sprintf(
            __('game/missions.mi_fleet_back_with_resources'),
            self::str($fleetRow['planet_end_name']),
            app(FleetEventsService::class)->targetLink($fleetRow, ''),
            self::str($fleetRow['planet_start_name']),
            app(FleetEventsService::class)->startLink($fleetRow, ''),
            $this->formatService->prettyNumber(self::int($fleetRow['fleet_resource_metal'])),
            $this->formatService->prettyNumber(self::int($fleetRow['fleet_resource_crystal'])),
            $this->formatService->prettyNumber(self::int($fleetRow['fleet_resource_deuterium']))
        );

        Functions::sendMessage(
            self::int($fleetRow['fleet_owner']),
            0,
            self::int($fleetRow['fleet_end_time']),
            1,
            __('game/missions.mi_fleet_command'),
            __('game/missions.mi_fleet_back_title'),
            $message
        );

        parent::restoreFleet($fleetRow);
        parent::removeFleet(self::int($fleetRow['fleet_id']));
    }

    private function updatePoints(BattleReport $report, PlayerGroup $afterBattleAttackers, PlayerGroup $afterBattleDefenders): void
    {
        $attackersBefore = $report->getRound('START')->getAfterBattleAttackers();
        $this->recordLosses($report->getPlayersLostShips($attackersBefore, $afterBattleAttackers));

        $defendersBefore = $report->getRound('START')->getAfterBattleDefenders();
        $this->recordLosses($report->getPlayersLostShips($defendersBefore, $afterBattleDefenders));
    }

    private function recordLosses(PlayerGroup $lostShipsAndDefense): void
    {
        foreach ($lostShipsAndDefense->getIterator() as $player) {
            foreach ($player->getIterator() as $fleet) {
                $lostFleet = [];

                foreach ($fleet->getIterator() as $shipId => $ship) {
                    $lostFleet[$shipId] = $ship->getCount();
                }

                $this->updateLostShipsAndDefensePoints($player->getId(), $lostFleet);
            }
        }
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    private function updateDebris(array $fleetRow, BattleReport $report): void
    {
        [$metal, $crystal] = $report->getDebris();

        if (($metal + $crystal) > 0) {
            $this->updatePlanetDebrisByCoords([
                'time' => time(),
                'debris' => [
                    'metal' => $metal,
                    'crystal' => $crystal,
                ],
                'coords' => [
                    'galaxy' => $fleetRow['fleet_end_galaxy'],
                    'system' => $fleetRow['fleet_end_system'],
                    'planet' => $fleetRow['fleet_end_planet'],
                ],
            ]);
        }
    }

    /**
     * Total cargo capacity of the surviving attackers.
     */
    private function getCapacity(PlayerGroup $players): int | float
    {
        $capacity = 0;

        foreach ($players->getIterator() as $playerId => $player) {
            foreach ($player->getIterator() as $fleet) {
                foreach ($fleet->getIterator() as $shipId => $shipType) {
                    $capacity += $shipType->getCount() * app(FleetsService::class)->getMaxStorage(
                        self::int($this->pricelist[$shipId]['capacity']),
                        $this->hyperspaceTechnology[$playerId] ?? 0,
                        (int) $shipId,
                        $this->playerClasses[$playerId] ?? new ClassBonuses()
                    );
                }
            }
        }

        return $capacity;
    }

    /**
     * Send the surviving attacking fleets home with their share of the loot,
     * remove the destroyed ones.
     *
     * @param array<string, mixed> $targetPlanet
     *
     * @return Loot
     */
    private function updateAttackers(PlayerGroup $playerGroupBeforeBattle, PlayerGroup $playerGroupAfterBattle, array $targetPlanet): array
    {
        $capacity = $this->getCapacity($playerGroupAfterBattle);
        $attackersWon = $playerGroupAfterBattle->battleResult == BATTLE_WIN;
        $emptyFleets = [];
        $steal = [
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
        ];

        $fleetIds = [];

        // attacking generals get wreck fields at their start planet
        foreach ($playerGroupBeforeBattle->getIterator() as $playerId => $player) {
            if (($this->playerClasses[$playerId] ?? new ClassBonuses())->player === PlayerClass::General) {
                $fleetIds = array_merge($fleetIds, array_map('intval', array_keys($player->getIterator())));
            }
        }

        $origins = app(WreckFieldService::class)->fleetOrigins($fleetIds);

        foreach ($playerGroupBeforeBattle->getIterator() as $playerId => $player) {
            foreach ($player->getIterator() as $fleetId => $fleet) {
                $survivingFleet = CombatSurvivors::fleet($playerGroupAfterBattle, $playerId, $fleetId);
                [$before, $lost] = CombatSurvivors::losses($fleet, $survivingFleet);
                $this->attackerLosses[] = ['origin' => $origins[(int) $fleetId] ?? null, 'player' => (int) $playerId, 'before' => $before, 'lost' => $lost];

                if ($survivingFleet === null) {
                    $emptyFleets[] = $fleetId;

                    continue;
                }

                $ships = CombatSurvivors::ships($fleet, $survivingFleet);
                $fleetSteal = [
                    'metal' => 0,
                    'crystal' => 0,
                    'deuterium' => 0,
                ];

                if ($attackersWon) {
                    $returning = $this->onAttackerVictory($playerId, $targetPlanet, $ships);

                    // nothing left to fly home (the mission destroyed the rest)
                    if ($returning === [] && $ships !== []) {
                        $emptyFleets[] = $fleetId;

                        continue;
                    }

                    $ships = $returning;
                    $fleetSteal = $this->fleetLoot($ships, $capacity, $targetPlanet, (int) $playerId, (int) $fleetId);

                    $steal['metal'] += $fleetSteal['metal'];
                    $steal['crystal'] += $fleetSteal['crystal'];
                    $steal['deuterium'] += $fleetSteal['deuterium'];
                }

                $this->updateReturningFleetData([
                    'ships' => FleetShips::encode($ships),
                    'amount' => array_sum($ships),
                    'stolen' => $fleetSteal,
                    'fleet_id' => $fleetId,
                ]);
                $this->returningFleets[(int) $fleetId] = [
                    'player' => (int) $playerId,
                    'ships' => $ships,
                    'loot' => $fleetSteal['metal'] + $fleetSteal['crystal'] + $fleetSteal['deuterium'],
                ];
            }
        }

        $this->deleteFleets($emptyFleets);

        return $steal;
    }

    /**
     * The share of the planet's resources a fleet takes home: its part of the
     * attackers' total cargo capacity, filled by plunder().
     *
     * @param array<int, int|float> $ships
     * @param array<string, mixed>  $targetPlanet
     *
     * @return Loot
     */
    private function fleetLoot(array $ships, int | float $capacity, array $targetPlanet, int $attackerId, int $fleetId = 0): array
    {
        $fleetCapacity = 0;

        foreach ($ships as $shipId => $amount) {
            $fleetCapacity += $amount * self::num($this->pricelist[$shipId]['capacity']);
        }

        $share = $this->defenderInactive ? ($this->playerClasses[$attackerId] ?? new ClassBonuses())->player->inactiveLootShare() : 0.5;
        $metal = self::num($targetPlanet['planet_metal']) * $fleetCapacity / $capacity;
        $crystal = self::num($targetPlanet['planet_crystal']) * $fleetCapacity / $capacity;
        $deuterium = self::num($targetPlanet['planet_deuterium']) * $fleetCapacity / $capacity;
        $priority = $this->lootPriorities[$fleetId] ?? '';

        // an admiral's plunder priority: the resources in that order
        if (self::isLootPriority($priority)) {
            return self::plunderInOrder($fleetCapacity, ['metal' => $metal, 'crystal' => $crystal, 'deuterium' => $deuterium], $share, $priority);
        }

        return self::plunder($fleetCapacity, $metal, $crystal, $deuterium, $share);
    }

    public static function isLootPriority(string $priority): bool
    {
        return in_array($priority, ['mcd', 'mdc', 'cmd', 'cdm', 'dmc', 'dcm'], true);
    }

    /**
     * Load the resources one after the other in the given order ('mcd' =
     * metal, crystal, deuterium), each up to the share that can be taken.
     *
     * @param array{metal: int|float, crystal: int|float, deuterium: int|float} $available
     *
     * @return Loot
     */
    protected static function plunderInOrder(int | float $capacity, array $available, float $share, string $order): array
    {
        $names = ['m' => 'metal', 'c' => 'crystal', 'd' => 'deuterium'];
        $loot = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];

        foreach (str_split($order) as $letter) {
            $resource = $names[$letter];
            $loot[$resource] = min($capacity, $available[$resource] * $share);
            $capacity -= $loot[$resource];
        }

        return $loot;
    }

    /**
     * Write the planet's surviving ships and defenses and subtract the loot;
     * update the fleets stationed at the planet, remove the destroyed ones.
     *
     * Bugfix: the survivors of every defending fleet, also of the fleets
     * stationed at the planet, used to be written to the planet's own ship
     * columns, while the stationed fleets themselves kept all their ships.
     *
     * @param array<string, mixed> $targetPlanet
     * @param Loot                 $steal
     */
    private function updateDefenders(PlayerGroup $playerGroupBeforeBattle, PlayerGroup $playerGroupAfterBattle, array $targetPlanet, array $steal): void
    {
        $ships = '';
        $emptyFleets = [];

        foreach ($playerGroupBeforeBattle->getIterator() as $playerId => $player) {
            foreach ($player->getIterator() as $fleetId => $fleet) {
                $survivingFleet = CombatSurvivors::fleet($playerGroupAfterBattle, $playerId, $fleetId);
                $survivors = CombatSurvivors::ships($fleet, $survivingFleet);

                if ($fleetId === self::PLANET_FORCES) {
                    $this->planetLosses = CombatSurvivors::losses($fleet, $survivingFleet);

                    foreach (array_keys($fleet->getIterator()) as $shipId) {
                        $ships .= '`' . $this->resource[$shipId] . '` = ' . ($survivors[$shipId] ?? 0) . ', ';
                    }

                    continue;
                }

                if ($survivingFleet === null) {
                    $emptyFleets[] = $fleetId;

                    continue;
                }

                $this->updateFleetShipsById([
                    'ships' => FleetShips::encode($survivors),
                    'amount' => array_sum($survivors),
                    'fleet_id' => $fleetId,
                ]);
            }
        }

        $this->updatePlanetLossesById([
            'ships' => $ships,
            'stolen' => $steal,
            'planet_id' => $targetPlanet['planet_id'],
        ]);

        $this->deleteFleets($emptyFleets);
    }

    /**
     * @param list<int> $fleetIds
     */
    private function deleteFleets(array $fleetIds): void
    {
        if ($fleetIds !== []) {
            $this->deleteMultipleFleetsByIds(implode(',', $fleetIds));
        }
    }

    /**
     * Split the loot of one fleet:
     * 1. Fill up to 1/3 of cargo capacity with metal
     * 2. Fill up to half remaining capacity with crystal
     * 3. The rest will be filled with deuterium
     * 4. If there is still capacity available fill half of it with metal
     * 5. Now fill the rest with crystal
     *
     * At most half of each resource of the planet can be taken ($share; a
     * discoverer takes 75 % from inactive players).
     *
     * @return Loot
     */
    protected static function plunder(int | float $capacity, int | float $metal, int | float $crystal, int | float $deuterium, float $share = 0.5): array
    {
        $metal *= $share;
        $crystal *= $share;
        $deuterium *= $share;

        $firstMetal = min($capacity / 3, $metal);
        $capacity -= $firstMetal;
        $metal -= $firstMetal;

        $firstCrystal = min($capacity / 2, $crystal);
        $capacity -= $firstCrystal;
        $crystal -= $firstCrystal;

        $stolenDeuterium = min($capacity, $deuterium);
        $capacity -= $stolenDeuterium;

        $secondMetal = min($capacity / 2, $metal);
        $capacity -= $secondMetal;

        $secondCrystal = min($capacity, $crystal);

        return [
            'metal' => 0 + $firstMetal + $secondMetal,
            'crystal' => 0 + $firstCrystal + $secondCrystal,
            'deuterium' => 0 + $stolenDeuterium,
        ];
    }
}
