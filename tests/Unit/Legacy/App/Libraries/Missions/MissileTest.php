<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries\Missions;

use App\Services\FormatService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Xgp\App\Core\Objects;
use Xgp\App\Libraries\Missions\Missile;

/**
 * Characterization of how interplanetary missiles destroy defenses. The
 * expected values are golden values captured from the original for-loop in
 * missileMission() (run against the real object config) before it was
 * extracted into impactDefenses().
 */
class MissileTest extends TestCase
{
    private const ORDER_DEFAULT = [401, 402, 403, 404, 405, 406, 407, 408, 503];
    private const ORDER_HEAVY_LASER_FIRST = [403, 401, 402, 404, 405, 406, 407, 408, 503];

    /**
     * @return array<string, array{0: float, 1: list<int>, 2: array<int, int>, 3: int, 4: array<int, int>, 5: string}>
     */
    public static function impactCases(): array
    {
        return [
            'destroys everything' => [
                100000.0, self::ORDER_DEFAULT, [401 => 10, 402 => 5], 0,
                [401 => 10, 402 => 5],
                '`defense_rocket_launcher` = `defense_rocket_launcher` - 10,`defense_light_laser` = `defense_light_laser` - 5,',
            ],
            'partial first target' => [
                1000.0, self::ORDER_DEFAULT, [401 => 10, 402 => 5], 0,
                [401 => 5],
                '`defense_rocket_launcher` = `defense_rocket_launcher` - 5,',
            ],
            'shielding reduces damage' => [
                1000.0, self::ORDER_DEFAULT, [401 => 10], 5,
                [401 => 3],
                '`defense_rocket_launcher` = `defense_rocket_launcher` - 3,',
            ],
            'primary objective first' => [
                5000.0, self::ORDER_HEAVY_LASER_FIRST, [401 => 10, 403 => 2], 0,
                [403 => 2, 401 => 10],
                '`defense_heavy_laser` = `defense_heavy_laser` - 2,`defense_rocket_launcher` = `defense_rocket_launcher` - 10,',
            ],
            'zero attack' => [
                0.0, self::ORDER_DEFAULT, [401 => 10], 0,
                [],
                '',
            ],
        ];
    }

    /**
     * @param list<int>       $order
     * @param array<int, int> $counts
     * @param array<int, int> $expectedDestroyed
     */
    #[DataProvider('impactCases')]
    public function testImpactDefensesMatchesTheOriginalLoop(
        float $attack,
        array $order,
        array $counts,
        int $shielding,
        array $expectedDestroyed,
        string $expectedQuery
    ): void {
        $resource = Objects::getInstance()->getObjects();
        $this->assertIsArray($resource);

        $targetData = ['research_shielding_technology' => $shielding];
        foreach ([401, 402, 403, 404, 405, 406, 407, 408, 502, 503] as $id) {
            $column = $resource[$id];
            $this->assertIsString($column);
            $targetData[$column] = $counts[$id] ?? 0;
        }

        $ref = new ReflectionMethod(Missile::class, 'impactDefenses');
        $impact = $ref->invoke(app(Missile::class), $attack, $order, $targetData);

        $this->assertIsArray($impact);
        $this->assertIsArray($impact['destroyed']);
        $this->assertSame($expectedQuery, $impact['query']);
        $this->assertEquals($expectedDestroyed, $impact['destroyed']);
        $this->assertSame(array_keys($expectedDestroyed), array_keys($impact['destroyed']), 'attack order');
    }

    /**
     * Regression: intercepting every missile used to leave the anti-ballistic
     * missiles untouched. Now one interceptor is used up per missile.
     */
    public function testInterceptingAllMissilesUsesUpOneInterceptorEach(): void
    {
        $missile = new class (app(FormatService::class)) extends Missile {
            /** @var list<array<string, mixed>> */
            public array $defenseUpdates = [];

            protected function updatePlanetDefenses(array $data): void
            {
                $this->defenseUpdates[] = $data;
            }
        };

        $result = (new ReflectionMethod(Missile::class, 'interceptAll'))->invoke($missile, ['planet_id' => 42], 3, 10);

        $this->assertSame([['destroyed_query' => '', 'amount' => 7, 'planet_id' => 42]], $missile->defenseUpdates);
        $this->assertIsArray($result);
        $this->assertSame([], $result[1]);
    }
}
