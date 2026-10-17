<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Attack;
use App\Libraries\Missions\Missions;

class AttackCharacterizationTest extends MissionLootCharacterizationTestCase
{
    protected function makeMission(): Missions
    {
        return app(Attack::class);
    }
}
