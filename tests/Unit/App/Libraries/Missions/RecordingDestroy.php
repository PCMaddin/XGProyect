<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Destroy;

/**
 * Destroy with recorded instead of executed database writes.
 */
class RecordingDestroy extends Destroy implements RecordingMission
{
    use RecordsCombatWrites;
}
