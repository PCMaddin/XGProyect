<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

use App\Libraries\FleetsLib;
use App\Libraries\Functions;
use App\Libraries\UpdatesLibrary;
use App\Services\FormatService;
use App\Services\Game\Formulas\FleetsService;
use Xgp\App\Libraries\BattleEngine\Core\Battle;
use Xgp\App\Libraries\BattleEngine\Core\BattleReport;
use Xgp\App\Libraries\BattleEngine\Models\PlayerGroup;
use Xgp\App\Libraries\BattleEngine\Utils\DebugManager;

/**
 * The battle shared by the attack and the moon destruction missions: build
 * the forces, fight, then write losses, loot, debris and the combat report.
 *
 * @phpstan-type Loot array{metal: int|float, crystal: int|float, deuterium: int|float}
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
abstract class CombatMission extends Missions
{
    use BuildsCombatForces;
    use SendsCombatReports;

    protected const SHIP_MIN_ID = 202;
    protected const SHIP_MAX_ID = 215;
    protected const DEFENSE_MIN_ID = 401;
    protected const DEFENSE_MAX_ID = 408;

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
     * Called for every surviving attacking fleet when the attackers won.
     *
     * @param array<string, mixed>  $targetPlanet
     * @param array<int, int|float> $ships surviving ships by id
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function onAttackerVictory(array $targetPlanet, array $ships): void
    {
    }

    /**
     * Whether the surviving attacking fleets fly home (false: they are removed).
     */
    protected function survivorsReturn(): bool
    {
        return true;
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
        return '';
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

        require_once LIB_PATH . 'BattleEngine' . DIRECTORY_SEPARATOR . 'Utils' . DIRECTORY_SEPARATOR . 'Includer.php';

        AttackLang::activate($this->resource);

        $this->releaseFleets($fleetRow);

        $targetUser = $this->getAllUserDataByUserId(self::int($targetPlanet['planet_user_id']));

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
        DebugManager::runDebugged([$battle, 'startBattle'])();

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
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    private function returnHome(array $fleetRow): void
    {
        $message = sprintf(
            __('game/missions.mi_fleet_back_with_resources'),
            self::str($fleetRow['planet_end_name']),
            FleetsLib::targetLink($fleetRow, ''),
            self::str($fleetRow['planet_start_name']),
            FleetsLib::startLink($fleetRow, ''),
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
                        $this->hyperspaceTechnology[$playerId] ?? 0
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

        foreach ($playerGroupBeforeBattle->getIterator() as $playerId => $player) {
            foreach ($player->getIterator() as $fleetId => $fleet) {
                $survivingFleet = CombatSurvivors::fleet($playerGroupAfterBattle, $playerId, $fleetId);

                if ($survivingFleet === null) {
                    $emptyFleets[] = $fleetId;

                    continue;
                }

                $ships = CombatSurvivors::ships($fleet, $survivingFleet);
                $fleetCapacity = 0;

                foreach ($ships as $shipId => $amount) {
                    $fleetCapacity += $amount * self::num($this->pricelist[$shipId]['capacity']);
                }

                $fleetSteal = [
                    'metal' => 0,
                    'crystal' => 0,
                    'deuterium' => 0,
                ];

                if ($attackersWon) {
                    $fleetSteal = self::plunder(
                        $fleetCapacity,
                        self::num($targetPlanet['planet_metal']) * $fleetCapacity / $capacity,
                        self::num($targetPlanet['planet_crystal']) * $fleetCapacity / $capacity,
                        self::num($targetPlanet['planet_deuterium']) * $fleetCapacity / $capacity
                    );

                    $this->onAttackerVictory($targetPlanet, $ships);

                    $steal['metal'] += $fleetSteal['metal'];
                    $steal['crystal'] += $fleetSteal['crystal'];
                    $steal['deuterium'] += $fleetSteal['deuterium'];
                }

                if (!$this->survivorsReturn()) {
                    $emptyFleets[] = $fleetId;

                    continue;
                }

                $this->updateReturningFleetData([
                    'ships' => FleetsLib::setFleetShipsArray($ships),
                    'amount' => array_sum($ships),
                    'stolen' => $fleetSteal,
                    'fleet_id' => $fleetId,
                ]);
            }
        }

        $this->deleteFleets($emptyFleets);

        return $steal;
    }

    /**
     * Write the surviving ships and defenses to the planet, subtract the loot
     * and remove the destroyed fleets.
     *
     * Legacy behaviour, kept as is (see MIGRATION.md, open points): the counts
     * of all defending fleets, also the ones of other players stationed at
     * the planet, are written to the planet's own ship columns.
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

                if ($survivingFleet === null) {
                    $emptyFleets[] = $fleetId;
                }

                $survivors = CombatSurvivors::ships($fleet, $survivingFleet);

                foreach (array_keys($fleet->getIterator()) as $shipId) {
                    $ships .= '`' . $this->resource[$shipId] . '` = ' . ($survivors[$shipId] ?? 0) . ', ';
                }
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
        $idString = implode(',', $fleetIds);

        // the planet's own forces (fleet id 0) alone are no fleet to delete
        if ($idString !== '' && $idString !== '0') {
            $this->deleteMultipleFleetsByIds($idString);
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
     * At most half of each resource of the planet can be taken.
     *
     * @return Loot
     */
    protected static function plunder(int | float $capacity, int | float $metal, int | float $crystal, int | float $deuterium): array
    {
        $metal /= 2;
        $crystal /= 2;
        $deuterium /= 2;

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
