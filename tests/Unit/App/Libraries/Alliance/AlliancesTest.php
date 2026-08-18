<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Alliance;

use App\Libraries\Alliance\Alliances;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Xgp\App\Core\Entity\AllianceEntity;

#[CoversClass(Alliances::class)]
class AlliancesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function allianceRow(int $owner): array
    {
        return ['alliance_id' => 1, 'alliance_owner' => $owner, 'alliance_ranks' => ''];
    }

    public function testIsOwnerComparesTheAllianceOwnerToTheUser(): void
    {
        $this->assertTrue((new Alliances([$this->allianceRow(5)], 5))->isOwner());
        $this->assertFalse((new Alliances([$this->allianceRow(5)], 6))->isOwner());
    }

    public function testOwnerHasAccessToAnyRank(): void
    {
        $alliances = new Alliances([$this->allianceRow(5)], 5);

        $this->assertTrue($alliances->hasAccess(3));
    }

    public function testCheckRankZeroShortCircuitsToFalse(): void
    {
        $alliances = new Alliances([$this->allianceRow(5)], 6);

        // legacy: `$rank != null` is false for 0 (0 == null loosely)
        $this->assertFalse($alliances->checkRank(0));
    }

    public function testGetCurrentAllianceOnEmptySetReturnsAnEmptyEntity(): void
    {
        $alliances = new Alliances([], 1);

        // legacy indexed [0] on an empty array here
        $this->assertInstanceOf(AllianceEntity::class, $alliances->getCurrentAlliance());
    }

    public function testFalsyRowsAreFilteredOut(): void
    {
        $alliances = new Alliances([[], $this->allianceRow(5)], 5);

        $this->assertCount(1, $alliances->getAlliances());
    }
}
