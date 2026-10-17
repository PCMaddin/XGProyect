<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\BattleEngine;

use App\Services\FormatService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Unit\App\Libraries\Missions\RecordingAttack;
use App\Libraries\BattleEngine\Core\Battle;
use App\Libraries\BattleEngine\Core\BattleReport;
use App\Libraries\BattleEngine\Models\Fleet;
use App\Libraries\BattleEngine\Models\Player;
use App\Libraries\BattleEngine\Models\PlayerGroup;
use App\Libraries\BattleEngine\Models\ShipType;
use App\Libraries\Missions\AttackLang;
use App\Libraries\Missions\CombatMission;
use App\Libraries\Missions\MissionObjects;

/**
 * Characterization of whole battles of the OPBE engine: seeded battles over
 * varied fleets (rapid fire, draws, several players, death stars, defense
 * repair, large stacks), comparing the complete combat report and every
 * result the missions read (winner, rounds, losses, debris, moon chance,
 * surviving and repaired forces).
 *
 * The expected values (tests/Fixtures/battle_outcomes.json) were captured
 * from the engine before its cleanup to a clean PHPStan/PHPMD baseline.
 *
 * @phpstan-type ForceSpec array<int, array{tech: array{0: int, 1: int, 2: int}, fleets: array<int, array<int, int>>}>
 */
class BattleOutcomeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 5) . '/app/Libraries/BattleEngine/Utils/Includer.php';
    }

    /**
     * @return array<string, array{0: int, 1: ForceSpec, 2: ForceSpec}>
     */
    public static function battles(): array
    {
        $rapidFire = [
            [1 => ['tech' => [10, 8, 9], 'fleets' => [10 => [204 => 200, 206 => 50]]]],
            [2 => ['tech' => [7, 7, 7], 'fleets' => [0 => [401 => 500, 402 => 20]]]],
        ];
        $draw = [
            [1 => ['tech' => [0, 0, 0], 'fleets' => [10 => [203 => 10]]]],
            [2 => ['tech' => [0, 0, 0], 'fleets' => [0 => [407 => 1]]]],
        ];
        $severalPlayers = [
            [
                1 => ['tech' => [12, 11, 12], 'fleets' => [10 => [207 => 30, 218 => 10], 11 => [203 => 40]]],
                4 => ['tech' => [9, 9, 10], 'fleets' => [12 => [205 => 80, 211 => 15]]],
            ],
            [
                2 => ['tech' => [11, 10, 11], 'fleets' => [0 => [401 => 300, 403 => 40, 406 => 5, 407 => 1, 408 => 1]]],
                3 => ['tech' => [10, 10, 10], 'fleets' => [20 => [206 => 60, 213 => 8]]],
            ],
        ];
        $deathStars = [
            [1 => ['tech' => [15, 15, 15], 'fleets' => [10 => [214 => 2, 213 => 20]]]],
            [2 => ['tech' => [12, 12, 12], 'fleets' => [0 => [401 => 2000, 402 => 1000, 404 => 200, 405 => 100, 406 => 20, 207 => 50]]]],
        ];
        $probe = [
            [1 => ['tech' => [0, 0, 0], 'fleets' => [10 => [210 => 1]]]],
            [2 => ['tech' => [0, 0, 0], 'fleets' => [0 => []]]],
        ];
        $defenseRepair = [
            [1 => ['tech' => [10, 10, 10], 'fleets' => [10 => [207 => 100]]]],
            [2 => ['tech' => [8, 8, 8], 'fleets' => [0 => [401 => 1000, 402 => 200, 404 => 50, 406 => 10]]]],
        ];
        $largeStacks = [
            [1 => ['tech' => [14, 13, 14], 'fleets' => [10 => [204 => 5000, 202 => 300]]]],
            [2 => ['tech' => [13, 13, 13], 'fleets' => [0 => [401 => 2000, 402 => 800, 210 => 50, 212 => 30]]]],
        ];

        return [
            'rapid fire, seed 1' => [1, ...$rapidFire],
            'rapid fire, seed 2' => [2, ...$rapidFire],
            'draw' => [3, ...$draw],
            'several players, seed 4' => [4, ...$severalPlayers],
            'several players, seed 5' => [5, ...$severalPlayers],
            'death stars, seed 6' => [6, ...$deathStars],
            'death stars, seed 7' => [7, ...$deathStars],
            'probe against nothing' => [8, ...$probe],
            'defense repair, seed 9' => [9, ...$defenseRepair],
            'defense repair, seed 10' => [10, ...$defenseRepair],
            'large stacks' => [11, ...$largeStacks],
        ];
    }

    /**
     * @param ForceSpec $spec
     */
    private static function forces(array $spec): PlayerGroup
    {
        $mission = new RecordingAttack(app(FormatService::class));
        $shipType = new ReflectionMethod(CombatMission::class, 'getShipType');
        $group = new PlayerGroup();

        foreach ($spec as $playerId => $player) {
            $fleets = [];
            foreach ($player['fleets'] as $fleetId => $ships) {
                $fleet = new Fleet($fleetId);
                foreach ($ships as $shipId => $count) {
                    $unit = $shipType->invoke($mission, $shipId, $count);
                    self::assertInstanceOf(ShipType::class, $unit);
                    $fleet->addShipType($unit);
                }
                $fleets[] = $fleet;
            }

            [$weapons, $shields, $armour] = $player['tech'];
            $group->addPlayer(new Player($playerId, $fleets, $weapons, $shields, $armour, 'player' . $playerId, 1, 2, $playerId));
        }

        return $group;
    }

    /**
     * @param ForceSpec $attackers
     * @param ForceSpec $defenders
     */
    private static function report(int $seed, array $attackers, array $defenders): BattleReport
    {
        AttackLang::activate(MissionObjects::names());

        mt_srand($seed);
        $battle = new Battle(self::forces($attackers), self::forces($defenders));
        $battle->startBattle();
        $report = $battle->getReport();
        // the missions always set the loot before the report is rendered
        $report->setSteal(['metal' => 12345.6, 'crystal' => 7890, 'deuterium' => 0]);

        return $report;
    }

    /**
     * Everything the missions read from a finished battle.
     *
     * @param ForceSpec $attackers
     * @param ForceSpec $defenders
     *
     * @return array<string, mixed>
     */
    public static function outcome(int $seed, array $attackers, array $defenders): array
    {
        $report = self::report($seed, $attackers, $defenders);

        return [
            'report' => md5((string) $report),
            'reportLength' => strlen((string) $report),
            'rounds' => $report->getLastRoundNumber(),
            'result' => [$report->attackerHasWin(), $report->defenderHasWin(), $report->isAdraw()],
            'attackersLostUnits' => $report->getAttackersLostUnits(),
            'defendersLostUnits' => $report->getDefendersLostUnits(),
            'defendersLostUnitsWithoutRepair' => $report->getDefendersDestroyedUnits(),
            'totalLost' => [$report->getTotalAttackersLostUnits(), $report->getTotalDefendersLostUnits()],
            'debris' => [$report->getAttackerDebris(), $report->getDefenderDebris(), $report->getDebris()],
            'moonProb' => $report->getMoonProb(),
            'tech' => [$report->getAttackersTech(), $report->getDefendersTech()],
            'afterBattle' => [(string) $report->getAfterBattleAttackers(), (string) $report->getAfterBattleDefenders()],
            'repaired' => [(string) $report->getAttackersRepaired(), (string) $report->getDefendersRepaired()],
            'ids' => [$report->getAttackersId(), $report->getDefendersId()],
            'nextRandom' => mt_rand(),
        ];
    }

    /**
     * @param ForceSpec $attackers
     * @param ForceSpec $defenders
     */
    #[DataProvider('battles')]
    public function testBattleOutcomeIsUnchanged(int $seed, array $attackers, array $defenders): void
    {
        // the engine's debug output used to be swallowed by an output buffer
        $this->expectOutputString('');

        $json = file_get_contents(dirname(__DIR__, 4) . '/Fixtures/battle_outcomes.json');
        $this->assertIsString($json);
        $expected = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($expected);

        // compared as JSON, exactly as captured (int/float and key order included)
        $this->assertSame(
            json_encode($expected[$this->dataName()], JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION),
            json_encode(self::outcome($seed, $attackers, $defenders), JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)
        );
    }

    /**
     * Regression: the report announced a new moon around "Planet [1:1:1]"
     * whenever no moon had been rolled (also in every moon destruction
     * report), and always with these placeholder name and coordinates.
     */
    public function testTheReportNamesThePlanetOfANewMoonOnlyWhenOneIsCreated(): void
    {
        [, $attackers, $defenders] = self::battles()['death stars, seed 6'];

        $withoutMoon = self::report(6, $attackers, $defenders);
        $this->assertStringNotContainsString('lunar satellite', (string) $withoutMoon);

        $withMoon = self::report(6, $attackers, $defenders);
        $withMoon->setMoonTarget('Tatooine', 3, 141, 9);
        $this->assertSame(20, $withMoon->getMoonProb());
        mt_srand(5); // first roll 11, below the chance of 20
        $this->assertIsArray($withMoon->tryMoon());
        $this->assertStringContainsString('in orbit the planet Tatooine [3:141:9]!', (string) $withMoon);
    }
}
