<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Destroy;
use App\Libraries\Missions\Missions;

class DestroyCharacterizationTest extends MissionLootCharacterizationTestCase
{
    protected function makeMission(): Missions
    {
        return app(Destroy::class);
    }
}
