<?php

declare(strict_types=1);

namespace Tests\Unit\App\Support\Concerns;

use App\Models\Alliance;
use App\Models\Fleets;
use App\Support\Concerns\HydratesModels;
use PHPUnit\Framework\Attributes\CoversTrait;
use Tests\TestCase;

#[CoversTrait(HydratesModels::class)]
class HydratesModelsTest extends TestCase
{
    use HydratesModels;

    public function testCastColumnsAreTypedAndMissingOnesEmpty(): void
    {
        $alliance = self::hydrateRow(Alliance::class, [
            'alliance_id' => '7',
            'alliance_name' => 'Dark Empire',
            'alliance_description' => null,
            'alliance_members' => '12',
        ]);

        $this->assertSame(7, $alliance->alliance_id);
        $this->assertSame('Dark Empire', $alliance->alliance_name);
        $this->assertSame(12, $alliance->alliance_members);
        $this->assertSame('', $alliance->alliance_description);
        $this->assertSame('', $alliance->alliance_text);
        $this->assertSame(0, $alliance->alliance_owner);
    }

    public function testAnEmptyRowGivesEmptyValues(): void
    {
        $fleet = self::hydrateRow(Fleets::class, []);

        $this->assertSame(0, $fleet->fleet_id);
        $this->assertSame(0, $fleet->fleet_owner);
        $this->assertSame('', $fleet->fleet_array);
    }

    /**
     * Regression: fleet_mess was cast to boolean, so a fleet staying at its
     * target (2) read as returning (1), e.g. in the admin fleet list.
     */
    public function testFleetMessKeepsItsThreeStates(): void
    {
        $this->assertSame(2, self::hydrateRow(Fleets::class, ['fleet_mess' => '2'])->fleet_mess);
        $this->assertSame(1, self::hydrateRow(Fleets::class, ['fleet_mess' => 1])->fleet_mess);
    }
}
