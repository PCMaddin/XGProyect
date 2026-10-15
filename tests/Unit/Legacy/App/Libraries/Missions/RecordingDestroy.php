<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries\Missions;

use Xgp\App\Libraries\Missions\Destroy;

/**
 * Destroy with recorded instead of executed database writes.
 */
class RecordingDestroy extends Destroy implements RecordingMission
{
    use RecordsCombatWrites;
}
