<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries\Missions;

use Xgp\App\Libraries\Missions\Attack;
use Xgp\App\Libraries\Missions\Missions;

class AttackCharacterizationTest extends MissionLootCharacterizationTestCase
{
    protected function makeMission(): Missions
    {
        return app(Attack::class);
    }
}
