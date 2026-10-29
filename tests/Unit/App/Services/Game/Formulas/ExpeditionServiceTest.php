<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Formulas;

use App\Services\Game\Formulas\ExpeditionService;
use App\Services\SettingsService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class ExpeditionServiceTest extends TestCase
{
    /** @var array<string, int> */
    private const DEFAULT_WEIGHTS = [
        'expedition_result_dark_matter_weight' => 900,
        'expedition_result_ships_weight' => 2200,
        'expedition_result_resources_weight' => 3250,
        'expedition_result_pirates_weight' => 560,
        'expedition_result_aliens_weight' => 260,
        'expedition_result_delay_weight' => 700,
        'expedition_result_early_weight' => 200,
        'expedition_result_nothing_weight' => 1830,
        'expedition_result_merchant_weight' => 17,
        'expedition_result_black_hole_weight' => 33,
        'expedition_result_item_weight' => 50,
        'expedition_dark_matter_source_small_weight' => 8900,
        'expedition_dark_matter_source_medium_weight' => 1000,
        'expedition_dark_matter_source_large_weight' => 100,
        'expedition_resource_type_metal_weight' => 6850,
        'expedition_resource_type_crystal_weight' => 2400,
        'expedition_resource_type_deuterium_weight' => 750,
        'expedition_resource_source_normal_weight' => 8900,
        'expedition_resource_source_large_weight' => 1000,
        'expedition_resource_source_xl_weight' => 100,
        'expedition_fleet_delay_2_weight' => 8900,
        'expedition_fleet_delay_3_weight' => 1000,
        'expedition_fleet_delay_5_weight' => 100,
    ];

    public function testGetMaxExpeditionPoints(): void
    {
        $expedition = $this->expeditionService();

        $this->assertEquals(2400, $expedition->getMaxExpeditionPoints(50000));
        $this->assertEquals(6000, $expedition->getMaxExpeditionPoints(500000));
        $this->assertEquals(9000, $expedition->getMaxExpeditionPoints(2500000));
        $this->assertEquals(12000, $expedition->getMaxExpeditionPoints(10000000));
        $this->assertEquals(15000, $expedition->getMaxExpeditionPoints(30000000));
        $this->assertEquals(18000, $expedition->getMaxExpeditionPoints(60000000));
        $this->assertEquals(21000, $expedition->getMaxExpeditionPoints(90000000));
        $this->assertEquals(25000, $expedition->getMaxExpeditionPoints(120000000));
    }

    public function testShipFindsCountHalfAsManyPoints(): void
    {
        $expedition = $this->expeditionService();

        $this->assertSame(1250, $expedition->getMaxShipFindPoints(50000));
        $this->assertSame(3000, $expedition->getMaxShipFindPoints(500000));
        $this->assertSame(6000, $expedition->getMaxShipFindPoints(10000000));
        $this->assertSame(12500, $expedition->getMaxShipFindPoints(120000000));
    }

    public function testResourceFindsCountAtLeast200Points(): void
    {
        $expedition = $this->expeditionService();

        $this->assertSame(200, $expedition->getResourceFindPoints(20, 50000));
        $this->assertSame(1500, $expedition->getResourceFindPoints(1500, 50000));
        $this->assertSame(2400, $expedition->getResourceFindPoints(6000, 50000));
    }

    public function testMaxExpeditionPointsAcceptStatisticFloats(): void
    {
        $expedition = $this->expeditionService();

        $this->assertEquals(6000, $expedition->getMaxExpeditionPoints(500000.0));
    }

    public function testCalculateExpeditionPoints(): void
    {
        $exampleIntegrity = 800;

        $expedition = $this->expeditionService();
        $result = $expedition->calculateExpeditionPoints($exampleIntegrity);

        $expectedResult = 4; // Expected result for $structuralIntegrity = 800

        $this->assertEquals($expectedResult, $result);
    }

    public function testGetExpeditionResult(): void
    {
        $expedition = $this->expeditionService();
        $result = $expedition->getExpeditionResult();

        $validResults = [
            'darkMatter', 'ships', 'resources', 'pirates', 'aliens', 'delay', 'early', 'nothing', 'merchant', 'blackHole', 'item'
        ];

        $this->assertContains($result, $validResults);
    }

    public function testExpeditionWeightTablesUseExpectedOdds(): void
    {
        $expedition = $this->expeditionService();

        $this->assertSame([
            'darkMatter' => 900,
            'ships' => 2200,
            'resources' => 3250,
            'pirates' => 560,
            'aliens' => 260,
            'delay' => 700,
            'early' => 200,
            'nothing' => 1830,
            'merchant' => 17,
            'blackHole' => 33,
            'item' => 50,
        ], $expedition->getExpeditionResultWeights());
        $this->assertSame(10000, array_sum($expedition->getExpeditionResultWeights()));

        $this->assertSame([
            'small' => 8900,
            'medium' => 1000,
            'large' => 100,
        ], $expedition->getDarkMatterSourceSizeWeights());

        $this->assertSame([
            'metal' => 6850,
            'crystal' => 2400,
            'deuterium' => 750,
        ], $expedition->getResourceTypeWeights());

        $this->assertSame([
            'normal' => 8900,
            'large' => 1000,
            'xl' => 100,
        ], $expedition->getResourceSourceSizeWeights());

        $this->assertSame([
            2 => 8900,
            3 => 1000,
            5 => 100,
        ], $expedition->getFleetDelayWeights());
    }

    public function testCalculateDarkMatterSourceSize(): void
    {
        $expedition = $this->expeditionService();
        $result = $expedition->calculateDarkMatterSourceSize();

        $validResults = ['small', 'medium', 'large'];

        $this->assertContains($result, $validResults);
    }

    public function testGetDarkMatterSourceSize(): void
    {
        $expedition = $this->expeditionService();

        $resultSmall = $expedition->getDarkMatterSourceSize('small');
        $this->assertGreaterThanOrEqual(300, $resultSmall);
        $this->assertLessThanOrEqual(400, $resultSmall);

        $resultMedium = $expedition->getDarkMatterSourceSize('medium');
        $this->assertGreaterThanOrEqual(500, $resultMedium);
        $this->assertLessThanOrEqual(700, $resultMedium);

        $resultLarge = $expedition->getDarkMatterSourceSize('large');
        $this->assertGreaterThanOrEqual(1000, $resultLarge);
        $this->assertLessThanOrEqual(1800, $resultLarge);
    }

    public function testCalculateResourceTypeObtained(): void
    {
        $expedition = $this->expeditionService();
        $result = $expedition->calculateResourceTypeObtained();

        $validResults = ['metal', 'crystal', 'deuterium'];

        $this->assertContains($result, $validResults);
    }

    public function testCalculateResourceSourceSize(): void
    {
        $expedition = $this->expeditionService();
        $result = $expedition->calculateResourceSourceSize();

        $validResults = ['normal', 'large', 'xl'];

        $this->assertContains($result, $validResults);
    }

    public function testGetResourceSourceSizeMultChances(): void
    {
        $expedition = $this->expeditionService();

        // even factors: 10-50, 50-100, 100-200
        foreach (['normal' => [10, 50], 'large' => [50, 100], 'xl' => [100, 200]] as $size => [$min, $max]) {
            for ($i = 0; $i < 50; $i++) {
                $factor = $expedition->getResourceSourceSizeMultChances($size);
                $this->assertGreaterThanOrEqual($min, $factor);
                $this->assertLessThanOrEqual($max, $factor);
                $this->assertSame(0, $factor % 2);
            }
        }
    }

    public function testShipFindFactors(): void
    {
        $expedition = $this->expeditionService();

        // 10-50, even 52-100, even 102-200
        foreach (['normal' => [10, 50, 1], 'large' => [52, 100, 2], 'xl' => [102, 200, 2]] as $size => [$min, $max, $step]) {
            for ($i = 0; $i < 50; $i++) {
                $factor = $expedition->getShipSourceSizeMultChances($size);
                $this->assertGreaterThanOrEqual($min, $factor);
                $this->assertLessThanOrEqual($max, $factor);
                $this->assertSame(0, $factor % $step);
            }
        }
    }

    public function testGetResourceFoundAmount(): void
    {
        $expedition = $this->expeditionService();

        // Test case 1: metal resource type
        $result = $expedition->getResourceFoundAmount(2, 100, 'metal');
        $this->assertEquals(200, $result);

        // Test case 2: crystal resource type
        $result = $expedition->getResourceFoundAmount(3, 150, 'crystal');
        $this->assertEquals(225, $result);

        // Test case 3: deuterium resource type
        $result = $expedition->getResourceFoundAmount(4, 200, 'deuterium');
        $this->assertEquals(266, $result);
    }

    public function testAShipFindHasAtLeast10000StructurePoints(): void
    {
        $expedition = $this->expeditionService();

        $this->assertSame(10000, $expedition->calculateShipFoundAmount(3, 150));
        $this->assertSame(150000, $expedition->calculateShipFoundAmount(50, 3000));
    }

    public function testAllFlyingShipsCountForTheExpeditionPoints(): void
    {
        $expedition = $this->expeditionService();

        foreach ([202, 203, 208, 209, 210, 214, 215] as $ship) {
            $this->assertTrue($expedition->countsForExpeditionPoints($ship));
        }

        $this->assertFalse($expedition->countsForExpeditionPoints(212));
    }

    public function testAFleetFindsShipsUpToOneStepHeavierThanItsHeaviest(): void
    {
        $expedition = $this->expeditionService();

        $this->assertSame([210, 202], $expedition->getFindableShips([210 => 5]));
        $this->assertSame([210, 202, 204, 203, 205], $expedition->getFindableShips([203 => 100, 202 => 3]));
        $this->assertSame([210, 202, 204, 203, 205, 219, 206, 207, 215, 211, 213, 218], $expedition->getFindableShips([213 => 1]));
        // the battlecruiser finds up to the bomber, the pathfinder up to the cruiser
        $this->assertSame([210, 202, 204, 203, 205, 219, 206, 207, 215, 211], $expedition->getFindableShips([215 => 1]));
        $this->assertSame([210, 202, 204, 203, 205, 219, 206], $expedition->getFindableShips([219 => 1]));
        // recyclers, colony ships and deathstars find nothing on their own
        $this->assertSame([], $expedition->getFindableShips([208 => 1, 209 => 10, 214 => 1]));
        $this->assertFalse($expedition->countsForExpeditionPoints(217));
        $this->assertSame([210, 202], $expedition->getFindableShips([209 => 10, 210 => 1]));
    }

    public function testAShipFindDrawsTypesAndNumbersUntilTheBudgetIsSpent(): void
    {
        $structure = [202 => 4000, 203 => 12000, 204 => 4000, 205 => 10000, 210 => 1000];

        // large cargos find probes, small cargos, light fighters, large
        // cargos and heavy fighters; budget 30000: heavy fighter (index 4),
        // 2 of 3; large cargo (3), 0 affordable; probe (0), 4 of 10; small
        // cargo (1), 1 of 1; then three draws that buy nothing
        $expedition = $this->scripted([4, 2, 3, 0, 4, 1, 1, 3, 2, 4]);

        $this->assertSame([202 => 1, 205 => 2, 210 => 4], $expedition->getFoundShips([203 => 100], 30000, $structure));
    }

    public function testShipsWithoutAStepAreNeverFound(): void
    {
        $structure = [203 => 12000, 208 => 30000, 209 => 16000, 210 => 1000, 214 => 9000000];

        // regression: each deathstar, recycler and colony ship brought a free one
        for ($i = 0; $i < 20; $i++) {
            $found = $this->expeditionService()->getFoundShips([203 => 1, 208 => 1, 209 => 5, 214 => 1], 1000000, $structure);
            $this->assertSame([], array_intersect_key($found, [208 => 0, 209 => 0, 214 => 0]));
            $this->assertLessThanOrEqual(1000000, array_sum(array_map(fn (int $id, int $count): int => $structure[$id] * $count, array_keys($found), $found)));
        }

        // no findable ship in the fleet, or a budget too small: nothing
        $this->assertSame([], $this->expeditionService()->getFoundShips([209 => 50, 214 => 1], 10000000, $structure));
        $this->assertSame([], $this->expeditionService()->getFoundShips([210 => 5], 500, $structure));
    }

    public function testGetFleetDeplay(): void
    {
        $expedition = $this->expeditionService();

        $result = $expedition->getFleetDeplay();
        $this->assertContains($result, [2, 3, 5]); // Assert that the result is one of the allowed values
    }

    public function testTheEnemyFleetIsAShareOfTheExpeditionPlusExtraShips(): void
    {
        $ships = [204 => 10, 207 => 3, 210 => 0];

        // pirates: 30 % (+5 % of it, -10 %), rounded down, plus 5 light fighters
        $this->assertSame([204 => 3 + 5], $this->scripted([50, -100])->getEnemyFleet('pirates', 'normal', $ships));
        // huge: 80 %, plus 2 battleships
        $this->assertSame([204 => 8, 207 => 2 + 2], $this->scripted([0, 0])->getEnemyFleet('pirates', 'huge', $ships));
        // aliens: 60 % rounded up, plus 3 battleships (for battlecruisers)
        $this->assertSame([204 => 6, 207 => 2 + 3], $this->scripted([0, 0])->getEnemyFleet('aliens', 'large', $ships));
        // normal aliens bring 5 heavy fighters, huge ones 2 destroyers
        $this->assertSame([204 => 5, 207 => 2, 205 => 5], $this->scripted([100, 100])->getEnemyFleet('aliens', 'normal', $ships));
        $this->assertSame([204 => 9, 207 => 3, 213 => 2], $this->scripted([0, 0])->getEnemyFleet('aliens', 'huge', $ships));
    }

    public function testPiratesAreBehindAndAliensAhead(): void
    {
        $this->assertSame(-3, $this->expeditionService()->getEnemyTechnologyBonus('pirates'));
        $this->assertSame(3, $this->expeditionService()->getEnemyTechnologyBonus('aliens'));
        $this->assertContains($this->expeditionService()->getEnemySize(), ['normal', 'large', 'huge']);
    }

    /**
     * The service with the given random numbers, in order.
     *
     * @param list<int> $randoms
     */
    private function scripted(array $randoms): ExpeditionService
    {
        return new class ($this->createStub(SettingsService::class), $randoms) extends ExpeditionService {
            /** @param list<int> $randoms */
            public function __construct(SettingsService $settings, private array $randoms)
            {
                parent::__construct($settings);
            }

            protected function random(int $min, int $max): int
            {
                $value = array_shift($this->randoms) ?? $min;

                return max($min, min($max, $value));
            }
        };
    }

    /** @param array<string, int> $overrides */
    private function expeditionService(array $overrides = []): ExpeditionService
    {
        $settings = $this->createStub(SettingsService::class);
        $weights = array_merge(self::DEFAULT_WEIGHTS, $overrides);

        $settings->method('getInt')->willReturnCallback(
            static function (string $setting) use ($weights): int {
                if (!array_key_exists($setting, $weights)) {
                    throw new InvalidArgumentException('Missing setting: ' . $setting);
                }

                return $weights[$setting];
            }
        );

        return new ExpeditionService($settings);
    }
}
