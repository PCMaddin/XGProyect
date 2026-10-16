<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Services\Game\SystemEspionageService;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The options of the system espionage stay within their limits.
 */
#[CoversClass(SystemEspionageService::class)]
class SystemEspionageServiceTest extends TestCase
{
    public function testTheOptionsStayWithinTheirLimits(): void
    {
        $service = new SystemEspionageService();

        $this->assertSame(['probes' => 4, 'targets' => 'both', 'activity' => 'both', 'skip_hours' => 0], $service->options([], 4));
        $this->assertSame(
            ['probes' => 999, 'targets' => 'both', 'activity' => 'both', 'skip_hours' => 720],
            $service->options(['probes' => '5000', 'targets' => 'stars', 'activity' => ['x'], 'skip_hours' => 9999], 1)
        );
        $this->assertSame(
            ['probes' => 1, 'targets' => 'moons', 'activity' => 'inactive', 'skip_hours' => 0],
            $service->options(['probes' => -3, 'targets' => 'moons', 'activity' => 'inactive', 'skip_hours' => -1], 1)
        );
    }
}
