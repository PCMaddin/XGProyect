<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Instances;

use App\Services\Instances\RewardPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The best ten of each ranking share its dark matter in steps: the first
 * gets most, never more than there is.
 */
#[CoversClass(RewardPlan::class)]
class RewardPlanTest extends TestCase
{
    public function testThePlacesShareTheRankingsDarkMatter(): void
    {
        $plan = RewardPlan::parse('total=100000, military=20000', '30,20,15,10,7,6,4,3,3,2');

        $this->assertSame(30_000, $plan->reward('total', 1));
        $this->assertSame(2_000, $plan->reward('total', 10));
        $this->assertSame(4_000, $plan->reward('military', 2));
        // nothing for another ranking or an eleventh place
        $this->assertSame(0, $plan->reward('ships', 1));
        $this->assertSame(0, $plan->reward('total', 11));

        $paid = 0;

        for ($rank = 1; $rank <= 10; $rank++) {
            $paid += $plan->reward('total', $rank);
        }

        $this->assertSame(100_000, $paid);
    }

    public function testTheConfiguredPlanAndItsStoredForm(): void
    {
        $plan = RewardPlan::configured();

        $this->assertSame(100_000, $plan->pools['total']);
        $this->assertCount(6, $plan->pools);
        $this->assertSame(100, array_sum($plan->shares));
        $this->assertEquals($plan, RewardPlan::fromJson($plan->toJson()));
        $this->assertNull(RewardPlan::fromJson('nonsense'));
        $this->assertTrue(RewardPlan::parse('', '')->isEmpty());
    }

    public function testAPlanThatPaysMoreThanItHasIsRefused(): void
    {
        foreach ([['total=100', '60,50'], ['total=100', '10,10,10,10,10,10,10,10,10,5,5'], ['gold=5', '100'], ['total=-5', '100'], ['total=many', '100'], ['total=5', 'x']] as [$rewards, $shares]) {
            try {
                RewardPlan::parse($rewards, $shares);
                $this->fail('accepted ' . $rewards . ' / ' . $shares);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
