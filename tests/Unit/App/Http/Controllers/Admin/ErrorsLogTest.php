<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\ErrorsController;
use App\Models\User;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The admin's error list reads the universe's log: one file, or one a day
 * (LOG_CHANNEL=daily, the default of .env.example) with the newest shown;
 * "delete all" removes every day.
 */
#[CoversClass(ErrorsController::class)]
class ErrorsLogTest extends DatabaseTestCase
{
    private string $dir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        $this->dir = storage_path('framework/testing/logs-' . getmypid());
        File::ensureDirectoryExists($this->dir);
        config(['logging.channels.single.path' => $this->dir . '/xgproyect.log', 'logging.channels.daily.path' => $this->dir . '/xgproyect.log']);

        $admin = new User(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password', 'home_planet_id' => 1, 'current_planet' => 1]);
        $admin->authlevel = 3;
        $admin->save();
        $this->admin = $admin;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function testTheNewestDailyLogIsShownAndAllAreDeleted(): void
    {
        File::put($this->dir . '/xgproyect-2026-10-18.log', "[2026-10-18 10:00:00] production.ERROR: Old trouble\n");
        File::put($this->dir . '/xgproyect-2026-10-19.log', "[2026-10-19 10:00:00] production.ERROR: Fresh trouble\n");
        touch($this->dir . '/xgproyect-2026-10-18.log', time() - 86400);

        $this->actingAs($this->admin)->get('/admin/errors')->assertOk()->assertSee('Fresh trouble')->assertDontSee('Old trouble');

        $this->actingAs($this->admin)->post(route('admin.errors.delete'));
        $this->assertSame([], File::glob($this->dir . '/*.log'));
    }
}
