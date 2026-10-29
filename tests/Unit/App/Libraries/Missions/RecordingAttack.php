<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Attack;

/**
 * Attack with recorded instead of executed database writes.
 */
class RecordingAttack extends Attack implements RecordingMission
{
    use RecordsCombatWrites;
}
