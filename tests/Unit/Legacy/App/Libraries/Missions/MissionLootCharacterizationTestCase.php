<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries\Missions;

use ReflectionMethod;
use Tests\TestCase;
use Xgp\App\Core\Objects;
use Xgp\App\Libraries\BattleEngine\Models\Defense;
use Xgp\App\Libraries\BattleEngine\Models\Ship;
use Xgp\App\Libraries\Missions\Missions;

/**
 * Characterization tests pinning the CURRENT behaviour of the deterministic,
 * type-sensitive cores shared by the attack-style mission handlers before they
 * are typed to PHPStan level 9:
 *
 *  - plunder():     the pure loot-split arithmetic (coercion must not change amounts)
 *  - getShipType(): the Ship/Defense factory wired from the Objects config
 *
 * These exercise the real OPBE combat objects and the real game-object config
 * (GameObjectRegistry, no database), so they lock in the observable results the
 * typing work must preserve.
 */
abstract class MissionLootCharacterizationTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Load the OPBE combat constants (COST_TO_ARMOUR, SHIELD_CELLS, ...)
        // needed to construct Ship/Defense objects.
        require_once dirname(__DIR__, 6) . '/legacy/app/Libraries/BattleEngine/Utils/Includer.php';
    }

    /**
     * Build the concrete mission handler under test (Attack or Destroy).
     */
    abstract protected function makeMission(): Missions;

    private function invoke(Missions $mission, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($mission, $method);
        $ref->setAccessible(true);

        return $ref->invoke($mission, ...$args);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: array{metal: int|float, crystal: int|float, deuterium: int|float}}>
     */
    public static function plunderCases(): array
    {
        // Golden values captured from the current implementation.
        return [
            'cargo exceeds half of each resource' => [3000, 1000, 1000, 1000, ['metal' => 500, 'crystal' => 500, 'deuterium' => 500]],
            'cargo is the binding constraint' => [300, 6000, 6000, 6000, ['metal' => 100, 'crystal' => 100, 'deuterium' => 100]],
            'huge cargo takes half of everything' => [100000, 1200, 800, 400, ['metal' => 600, 'crystal' => 400, 'deuterium' => 200]],
            'odd division keeps fractional amount' => [1000, 901, 0, 0, ['metal' => 450.5, 'crystal' => 0, 'deuterium' => 0]],
            'zero cargo steals nothing' => [0, 5000, 5000, 5000, ['metal' => 0, 'crystal' => 0, 'deuterium' => 0]],
        ];
    }

    /**
     * Run plunder() and validate the shape of its result.
     *
     * @return array{metal: int|float, crystal: int|float, deuterium: int|float}
     */
    private function plunder(int $capacity, int $metal, int $crystal, int $deuterium): array
    {
        $steal = $this->invoke($this->makeMission(), 'plunder', $capacity, $metal, $crystal, $deuterium);

        if (!is_array($steal)) {
            $this->fail('plunder() must return an array');
        }

        $result = [];
        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $value = $steal[$resource] ?? null;
            if (!is_int($value) && !is_float($value)) {
                $this->fail("plunder() must return a numeric '{$resource}' amount");
            }
            $result[$resource] = $value;
        }

        return $result;
    }

    /**
     * @param array{metal: int|float, crystal: int|float, deuterium: int|float} $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('plunderCases')]
    public function testPlunderSplitsLootAsBefore(int $capacity, int $metal, int $crystal, int $deuterium, array $expected): void
    {
        $steal = $this->plunder($capacity, $metal, $crystal, $deuterium);

        // assertEqualsWithDelta (not assertSame): the computed AMOUNT must be
        // preserved, while an int/float representation shift from typing is tolerated.
        $this->assertEqualsWithDelta($expected['metal'], $steal['metal'], 0.0001);
        $this->assertEqualsWithDelta($expected['crystal'], $steal['crystal'], 0.0001);
        $this->assertEqualsWithDelta($expected['deuterium'], $steal['deuterium'], 0.0001);
    }

    public function testPlunderNeverExceedsCargoCapacity(): void
    {
        $steal = $this->plunder(500, 100000, 100000, 100000);
        $total = $steal['metal'] + $steal['crystal'] + $steal['deuterium'];

        $this->assertEqualsWithDelta(500, $total, 0.0001);
    }

    public function testGetShipTypeBuildsShipForShipIds(): void
    {
        $mission = $this->makeMission();
        $price = Objects::getInstance()->getPrice();
        $combat = Objects::getInstance()->getCombatSpecs();

        /** @var Ship $ship */
        $ship = $this->invoke($mission, 'getShipType', 202, 7);

        $this->assertInstanceOf(Ship::class, $ship);
        $this->assertSame(202, $ship->getId());
        $this->assertEqualsWithDelta(7, $ship->getCount(), 0.0001);
        $this->assertSame([$price[202]['metal'], $price[202]['crystal']], $ship->getCost());
        $this->assertEqualsWithDelta($combat[202]['shield'], $ship->getShield(), 0.0001);
        $this->assertEqualsWithDelta($combat[202]['attack'], $ship->getPower(), 0.0001);
    }

    /**
     * Regression: units without a rapid-fire table ('sd') used to be built with
     * an int 0 instead of an array, which crashed the attack/destroy missions
     * with a TypeError once ShipType's constructor was typed.
     */
    public function testGetShipTypeWiresRapidFireAndToleratesMissingTable(): void
    {
        $mission = $this->makeMission();
        $combat = Objects::getInstance()->getCombatSpecs();

        $this->assertArrayNotHasKey('sd', $combat[401], 'precondition: 401 has no rapid-fire table');

        /** @var Ship $smallCargo */
        $smallCargo = $this->invoke($mission, 'getShipType', 202, 1);
        /** @var Defense $rocketLauncher */
        $rocketLauncher = $this->invoke($mission, 'getShipType', 401, 1);
        /** @var Ship $probe */
        $probe = $this->invoke($mission, 'getShipType', 210, 1);

        // 202 has rapid fire against 210 (espionage probe) per the config
        $this->assertSame($combat[202]['sd'][210], $smallCargo->getRfTo($probe));
        // no rapid-fire table -> no rapid fire against anything
        $this->assertSame([], $rocketLauncher->getRF());
        $this->assertSame(0, $rocketLauncher->getRfTo($probe));
    }

    public function testGetShipTypeBuildsDefenseForDefenseIds(): void
    {
        $mission = $this->makeMission();
        $price = Objects::getInstance()->getPrice();
        $combat = Objects::getInstance()->getCombatSpecs();

        /** @var Defense $defense */
        $defense = $this->invoke($mission, 'getShipType', 401, 3);

        $this->assertInstanceOf(Defense::class, $defense);
        $this->assertSame(401, $defense->getId());
        $this->assertEqualsWithDelta(3, $defense->getCount(), 0.0001);
        $this->assertSame([$price[401]['metal'], $price[401]['crystal']], $defense->getCost());
        $this->assertEqualsWithDelta($combat[401]['shield'], $defense->getShield(), 0.0001);
        $this->assertEqualsWithDelta($combat[401]['attack'], $defense->getPower(), 0.0001);
    }
}
