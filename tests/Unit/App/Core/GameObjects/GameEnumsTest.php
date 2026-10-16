<?php

declare(strict_types=1);

namespace Tests\Unit\App\Core\GameObjects;

use App\Core\Enumerators\ResearchEnumerator;
use App\Enums\Game\BuildingCategory;
use App\Enums\Game\DriveType;
use PHPUnit\Framework\TestCase;

class GameEnumsTest extends TestCase
{
    // ── DriveType ────────────────────────────────────────────────────

    public function testDriveTypeValues(): void
    {
        $this->assertSame('combustion', DriveType::Combustion->value);
        $this->assertSame('impulse', DriveType::Impulse->value);
        $this->assertSame('hyperspace', DriveType::Hyperspace->value);
        $this->assertSame('none', DriveType::None->value);
    }

    public function testDriveTypeSpeedBonus(): void
    {
        $this->assertSame(0.1, DriveType::Combustion->speedBonus());
        $this->assertSame(0.2, DriveType::Impulse->speedBonus());
        $this->assertSame(0.3, DriveType::Hyperspace->speedBonus());
        $this->assertSame(0.0, DriveType::None->speedBonus());
    }

    public function testDriveTypeResearchId(): void
    {
        $this->assertSame(ResearchEnumerator::RESEARCH_COMBUSTION_DRIVE, DriveType::Combustion->researchId());
        $this->assertSame(ResearchEnumerator::RESEARCH_IMPULSE_DRIVE, DriveType::Impulse->researchId());
        $this->assertSame(ResearchEnumerator::RESEARCH_HYPERSPACE_DRIVE, DriveType::Hyperspace->researchId());
        $this->assertNull(DriveType::None->researchId());
    }

    public function testDriveTypeCaseCount(): void
    {
        $this->assertCount(4, DriveType::cases());
    }

    // ── BuildingCategory ─────────────────────────────────────────────

    public function testBuildingCategoryValues(): void
    {
        $this->assertSame('resource', BuildingCategory::Resource->value);
        $this->assertSame('facility', BuildingCategory::Facility->value);
        $this->assertSame('moon', BuildingCategory::Moon->value);
    }

    public function testBuildingCategoryCaseCount(): void
    {
        $this->assertCount(3, BuildingCategory::cases());
    }
}
