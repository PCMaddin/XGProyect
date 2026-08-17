<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Research;

use App\Libraries\Research\Researches;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Xgp\App\Core\Entity\ResearchEntity;

#[CoversClass(Researches::class)]
class ResearchesTest extends TestCase
{
    public function testWrapsRowsIntoEntities(): void
    {
        $research = new Researches([
            ['research_user_id' => 7, 'research_computer_technology' => 4],
        ]);

        $this->assertCount(1, $research->getResearch());
        $this->assertSame(4, $research->getCurrentResearch()->getResearchComputerTechnology());
    }

    public function testGetCurrentResearchOnEmptySetReturnsAnEmptyEntity(): void
    {
        $research = new Researches([]);

        // legacy indexed [0] on an empty array here
        $this->assertInstanceOf(ResearchEntity::class, $research->getCurrentResearch());
    }
}
