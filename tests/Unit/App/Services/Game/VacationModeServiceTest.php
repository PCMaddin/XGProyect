<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Core\GameObjects\GameObjectRegistry;
use App\Models\Preferences;
use App\Services\Game\VacationModeService;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(VacationModeService::class)]
class VacationModeServiceTest extends TestCase
{
    public function testProducerPercentsCoverTheRegisteredProducers(): void
    {
        $this->assertSame([
            'planet_building_metal_mine_percent' => 0,
            'planet_building_crystal_mine_percent' => 0,
            'planet_building_deuterium_sintetizer_percent' => 0,
            'planet_building_solar_plant_percent' => 0,
            'planet_building_fusion_reactor_percent' => 0,
            'planet_ship_solar_satellite_percent' => 0,
        ], $this->service()->producerPercents(0));
    }

    public function testVacationModeCanBeRemovedAfterTwoDays(): void
    {
        $service = $this->service();
        $recent = new Preferences(['preference_vacation_mode' => time() - ONE_DAY]);
        $old = new Preferences(['preference_vacation_mode' => time() - ONE_DAY * 2 - 1]);

        $this->assertTrue($service->isActive($recent));
        $this->assertFalse($service->canBeRemoved($recent));
        $this->assertTrue($service->canBeRemoved($old));
        $this->assertFalse($service->isActive(new Preferences()));
    }

    private function service(): VacationModeService
    {
        return new VacationModeService(new GameObjectRegistry());
    }
}
