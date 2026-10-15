<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries\Missions;

use Xgp\App\Libraries\Missions\Destroy;
use Xgp\App\Libraries\Missions\Missions;

class DestroyCharacterizationTest extends MissionLootCharacterizationTestCase
{
    protected function makeMission(): Missions
    {
        return app(Destroy::class);
    }
}
