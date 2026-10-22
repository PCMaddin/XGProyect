<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\MissionControlLib;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

#[CoversClass(MissionControlLib::class)]
class MissionControlFleetsMysqlTest extends MysqlTestCase
{
    use ChecksArrivingFleets;
}
