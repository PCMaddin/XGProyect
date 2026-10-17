<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Admin;

use App\Services\Admin\HomeService;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Regression: .env.example, which the release package installs from, had
 * APP_DEBUG=true, so error pages showed every visitor source code, queries
 * and request data.
 */
#[CoversClass(HomeService::class)]
class HomeServiceDebugModeTest extends TestCase
{
    public function testTheExampleEnvironmentIsForProduction(): void
    {
        $example = (string) file_get_contents(dirname(__DIR__, 5) . '/.env.example');

        $this->assertStringContainsString("\nAPP_ENV=production\n", $example);
        $this->assertStringContainsString("\nAPP_DEBUG=false\n", $example);
    }

    public function testDebugModeIsReported(): void
    {
        $service = app(HomeService::class);

        config(['app.debug' => true]);
        $this->assertTrue($service->isDebugModeOn());

        config(['app.debug' => false]);
        $this->assertFalse($service->isDebugModeOn());
    }
}
