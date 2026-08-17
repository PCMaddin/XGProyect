<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Game;

use App\Libraries\Game\AcsFleets;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Xgp\App\Core\Entity\AcsFleetEntity;

#[CoversClass(AcsFleets::class)]
class AcsFleetsTest extends TestCase
{
    public function testWrapsRowsIntoEntities(): void
    {
        $group = new AcsFleets([
            ['acs_id' => 1, 'acs_name' => 'Alpha'],
            ['acs_id' => 2, 'acs_name' => 'Beta'],
        ]);

        $entities = $group->getAcs();

        $this->assertCount(2, $entities);
        $this->assertSame('Alpha', $entities[0]->getAcsFleetName());
        $this->assertSame('Beta', $entities[1]->getAcsFleetName());
    }

    public function testGetFirstAcsReturnsTheFirstEntity(): void
    {
        $group = new AcsFleets([['acs_id' => 7, 'acs_name' => 'Gamma']]);

        $this->assertSame('Gamma', $group->getFirstAcs()->getAcsFleetName());
    }

    public function testGetFirstAcsOnEmptySetReturnsAnEmptyEntity(): void
    {
        $group = new AcsFleets([]);

        // legacy indexed [0] on an empty array here
        $this->assertInstanceOf(AcsFleetEntity::class, $group->getFirstAcs());
    }
}
