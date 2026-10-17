<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services;

use App\Services\SettingsService;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The options are loaded once per request (scoped binding), not by every
 * service that reads them; a value written is read back at once.
 */
#[CoversClass(SettingsService::class)]
class SettingsServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OptionsTableSeeder::class);
    }

    public function testTheOptionsAreLoadedOncePerRequest(): void
    {
        $loads = 0;
        DB::listen(function (QueryExecuted $query) use (&$loads): void {
            if (str_contains($query->sql, 'options') && str_starts_with(strtolower($query->sql), 'select')) {
                $loads++;
            }
        });

        $this->assertSame(app(SettingsService::class), app(SettingsService::class));

        for ($read = 0; $read < 20; $read++) {
            app(SettingsService::class)->getString('game_name');
            app(SettingsService::class)->getInt('game_speed');
        }

        $this->assertSame(1, $loads);

        // the next request loads them again
        $this->app->forgetScopedInstances();
        app(SettingsService::class)->getString('game_name');
        $this->assertSame(2, $loads);
    }

    public function testAWrittenValueIsReadBackAtOnce(): void
    {
        $settings = app(SettingsService::class);
        $this->assertSame(2500, $settings->getInt('game_speed'));

        $settings->write('game_speed', 5000);
        $settings->write('bots_play', false);
        $settings->write('new_option', 'yes');

        $this->assertSame(5000, $settings->getInt('game_speed'));
        $this->assertFalse($settings->getBool('bots_play'));
        $this->assertSame('yes', $settings->getString('new_option'));

        // and the table holds the same
        $this->app->forgetScopedInstances();
        $fresh = app(SettingsService::class);
        $this->assertSame(5000, $fresh->getInt('game_speed'));
        $this->assertFalse($fresh->getBool('bots_play'));
        $this->assertSame('yes', $fresh->getString('new_option'));
    }
}
