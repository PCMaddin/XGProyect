<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\MissionControlLib;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

#[CoversClass(MissionControlLib::class)]
class MissionControlFleetsTest extends DatabaseTestCase
{
    use ChecksArrivingFleets;
}
