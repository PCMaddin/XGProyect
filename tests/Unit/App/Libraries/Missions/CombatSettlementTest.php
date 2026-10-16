<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\FleetsLib;
use App\Services\FormatService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;
use App\Core\Objects;
use App\Libraries\BattleEngine\Core\Battle;
use App\Libraries\BattleEngine\Models\PlayerGroup;
use App\Libraries\BattleEngine\Utils\LangManager;
use App\Libraries\Missions\AttackLang;
use App\Libraries\Missions\CombatMission;
use App\Libraries\Missions\Destroy;

/**
 * Characterization of the attack and moon destruction missions around the
 * battle: building the forces, then writing survivors, loot, planet losses,
 * lost points and debris (all database writes recorded).
 *
 * The expected values (tests/Fixtures/combat_settlement.json) are golden
 * values captured from the original Attack/Destroy implementations, before
 * the shared code was extracted into CombatMission, with the same seeded
 * battles. Three scenarios were updated on purpose by later game-logic
 * fixes (see MIGRATION.md): "acs with stationed defender" (the planet's own
 * forces stay a fleet of their own; stationed fleets are updated instead of
 * being written to the planet) and "destroy wins" / "destroy wins 3" (only
 * the death stars are lost when the destruction fails, and they count as
 * lost units).
 */
class CombatSettlementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 5) . '/app/Libraries/BattleEngine/Utils/Includer.php';
    }

    /**
     * @param array<int, int> $ships
     *
     * @return array<string, mixed>
     */
    private static function fleetRow(int $id, int $owner, array $ships, int $group = 0): array
    {
        return [
            'fleet_id' => $id,
            'fleet_owner' => $owner,
            'fleet_group' => $group,
            'fleet_array' => FleetsLib::setFleetShipsArray($ships),
            'fleet_start_galaxy' => 1,
            'fleet_start_system' => 2,
            'fleet_start_planet' => $owner,
            'fleet_end_galaxy' => 1,
            'fleet_end_system' => 5,
            'fleet_end_planet' => 7,
            'fleet_end_type' => 1,
            'research_hyperspace_technology' => $owner % 3,
            'planet_start_name' => 'Home',
            'planet_end_name' => 'Target',
        ];
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: list<array<string, mixed>>, 3: list<array<string, mixed>>, 4: array<string, mixed>}>
     */
    public static function scenarios(): array
    {
        $planet = [
            'planet_id' => 99,
            'planet_user_id' => 2,
            'planet_name' => 'Target',
            'planet_diameter' => 8000,
            'planet_metal' => 100000.5,
            'planet_crystal' => 50000,
            'planet_deuterium' => 20000,
        ];

        return [
            'attacker wins' => ['attack', 11, [self::fleetRow(10, 1, [206 => 50, 203 => 20])], [], $planet + ['defense_rocket_launcher' => 10, 'ship_light_fighter' => 2]],
            'defender wins' => ['attack', 12, [self::fleetRow(10, 1, [204 => 5])], [], $planet + ['defense_rocket_launcher' => 100, 'defense_heavy_laser' => 10]],
            'acs with stationed defender' => [
                'attack',
                13,
                [self::fleetRow(11, 1, [206 => 30, 202 => 10], 5), self::fleetRow(12, 1, [207 => 5], 5), self::fleetRow(13, 4, [203 => 15, 205 => 20], 5)],
                [self::fleetRow(20, 3, [205 => 5, 204 => 10]), self::fleetRow(21, 2, [202 => 3])],
                $planet + ['defense_rocket_launcher' => 20, 'ship_cruiser' => 4],
            ],
            'empty planet' => ['attack', 14, [self::fleetRow(10, 1, [202 => 3])], [], $planet],
            'destroy wins' => ['destroy', 15, [self::fleetRow(10, 1, [214 => 3, 206 => 10])], [], $planet + ['defense_rocket_launcher' => 10]],
            'destroy wins 2' => ['destroy', 99, [self::fleetRow(10, 1, [214 => 1, 206 => 10])], [], $planet + ['defense_rocket_launcher' => 5]],
            'destroy wins 3' => ['destroy', 7, [self::fleetRow(10, 1, [214 => 2])], [], $planet],
            'destroy loses' => ['destroy', 16, [self::fleetRow(10, 1, [214 => 1])], [], $planet + ['defense_plasma_turret' => 50, 'defense_gauss_cannon' => 50]],
        ];
    }

    /**
     * Private methods of CombatMission are reflected on the base class, the
     * (overridden) protected ones on the mission itself.
     */
    private static function invoke(CombatMission $mission, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(CombatMission::class, $method);

        if (!$ref->isPrivate()) {
            $ref = new ReflectionMethod($mission, $method);
        }

        return $ref->invoke($mission, ...$args);
    }

    private static function mission(string $type): CombatMission & RecordingMission
    {
        $formatService = app(FormatService::class);

        return $type === 'attack' ? new RecordingAttack($formatService) : new RecordingDestroy($formatService);
    }

    /**
     * @return array<string, mixed>
     */
    private static function expected(string $scenario): array
    {
        $json = file_get_contents(dirname(__DIR__, 4) . '/Fixtures/combat_settlement.json');
        self::assertIsString($json);

        $fixture = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture[$scenario]);

        /** @var array<string, mixed> */
        return $fixture[$scenario];
    }

    /**
     * @param list<array<string, mixed>> $attackRows
     * @param list<array<string, mixed>> $defenderRows
     * @param array<string, mixed>       $targetPlanet
     */
    #[DataProvider('scenarios')]
    public function testSettlementMatchesTheOriginalImplementation(
        string $type,
        int $seed,
        array $attackRows,
        array $defenderRows,
        array $targetPlanet
    ): void {
        $expected = self::expected((string) $this->dataName());
        $targetUser = [
            'id' => 2,
            'name' => 'defender',
            'research_weapons_technology' => 3,
            'research_shielding_technology' => 2,
            'research_armour_technology' => 4,
            'current_planet' => 99,
        ];

        $mission = self::mission($type);
        LangManager::getInstance()->setImplementation(new AttackLang(Objects::getInstance()->getObjects()));

        $attackers = count($attackRows) > 1
            ? self::invoke($mission, 'fleetsGroup', $attackRows)
            : self::invoke($mission, 'attackingFleet', $attackRows[0]);
        $defenders = self::invoke($mission, 'fleetsGroup', $defenderRows, $targetUser);
        $this->assertInstanceOf(PlayerGroup::class, $attackers);
        $this->assertInstanceOf(PlayerGroup::class, $defenders);

        self::invoke($mission, 'addPlanetForces', $defenders, $targetUser, $targetPlanet, $attackRows[0]);

        $this->assertSame($expected['forces'], ['attackers' => (string) $attackers, 'defenders' => (string) $defenders]);

        mt_srand($seed);
        $battle = new Battle($attackers, $defenders);
        $battle->startBattle();
        $report = $battle->getReport();

        self::invoke($mission, 'settleBattle', $attackRows[0], $report, $targetPlanet);

        $this->assertSame($expected['report'], md5((string) $report));
        $this->assertSame($expected['attackerWins'], $report->attackerHasWin());
        $this->assertEquals($expected['steal'], $report->getSteal());
        $this->assertEquals($expected['calls'], $mission->recordedCalls());

        if ($mission instanceof Destroy) {
            $this->assertSame($expected['destruction'], (new ReflectionProperty(Destroy::class, 'destruction'))->getValue($mission));
            $this->assertSame($expected['appendix'], self::invoke($mission, 'reportAppendix', $attackRows[0], $report));
        }

        $title = self::invoke($mission, 'reportTitle', 'Target', app(FormatService::class)->prettyCoords(1, 5, 7));
        $this->assertSame($expected['link'], self::invoke($mission, 'buildReportLink', 'green', 'RID', $title));
    }
}
