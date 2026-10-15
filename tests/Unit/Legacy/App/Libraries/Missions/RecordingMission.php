<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries\Missions;

/**
 * A mission whose database writes are recorded instead of executed.
 */
interface RecordingMission
{
    /**
     * @return list<list<mixed>>
     */
    public function recordedCalls(): array;
}
