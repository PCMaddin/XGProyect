<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Admin;

use App\Http\Controllers\Admin\Ajax\UpdateCheckController;
use App\Services\Admin\HomeService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The update check asks updates.xgproyect.org from every admin page. It is
 * off unless XGP_UPDATE_CHECK is set; then nothing leaves the server.
 */
#[CoversClass(HomeService::class)]
#[CoversClass(UpdateCheckController::class)]
class UpdateCheckTest extends TestCase
{
    public function testOffByDefault(): void
    {
        $this->assertFalse(config('version.update_check'));
    }

    public function testWhenOffNothingIsAsked(): void
    {
        config(['version.update_check' => false]);
        Http::fake();

        $this->assertFalse($this->homeService('4.0.0')->isVersionOutdated());
        $this->assertSame('[]', (new UpdateCheckController())()->getContent());

        Http::assertNothingSent();
    }

    public function testWhenOnANewerReleaseIsReported(): void
    {
        config(['version.update_check' => true]);
        Http::fake(['updates.xgproyect.org/*' => Http::response(['version' => '4.1.0'])]);

        $this->assertTrue($this->homeService('4.0.0')->isVersionOutdated());
        $this->assertFalse($this->homeService('4.1.0')->isVersionOutdated());
        $this->assertSame('{"version":"4.1.0"}', (new UpdateCheckController())()->getContent());
    }

    private function homeService(string $version): HomeService
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getString')->willReturnMap([['version', $version]]);
        $this->app->instance(SettingsService::class, $settings);

        return $this->app->make(HomeService::class);
    }
}
