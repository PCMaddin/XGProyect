<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Home;

use App\Http\Controllers\Home\WelcomeController;
use App\Models\Options;
use Illuminate\Database\Migrations\Migration;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The home page showed the server logo from xgproyect.org by default. The
 * default is now the logo in public/assets/images; existing installs that
 * still had the old default are switched to it, a logo the admin set stays.
 */
#[CoversClass(WelcomeController::class)]
class WelcomeLogoTest extends DatabaseTestCase
{
    private const OLD_DEFAULT = 'https://xgproyect.org/wp-content/uploads/2019/10/xgp-new-logo-white.png';

    public function testWithoutALogoTheHomePageShowsTheShippedOne(): void
    {
        $page = $this->homePageWithLogo('');

        $this->assertStringContainsString('<img src="' . asset('assets/images/xgp-logo-white.png') . '"', $page);
        $this->assertStringNotContainsString('xgproyect.org/wp-content', $page);
    }

    public function testTheHomePageShowsTheLogoOfTheAdmin(): void
    {
        $this->assertStringContainsString('<img src="https://example.org/my-logo.png"', $this->homePageWithLogo('https://example.org/my-logo.png'));
    }

    public function testTheMigrationReplacesOnlyTheOldDefault(): void
    {
        $this->assertSame('', $this->logoAfterMigration(self::OLD_DEFAULT));
        $this->assertSame('', $this->logoAfterMigration(str_replace('https://', 'http://', self::OLD_DEFAULT)));
        $this->assertSame('https://example.org/my-logo.png', $this->logoAfterMigration('https://example.org/my-logo.png'));
    }

    private function homePageWithLogo(string $logo): string
    {
        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret']);

        foreach (['lang' => 'en', 'game_name' => 'XG Proyect', 'game_logo' => $logo, 'forum_url' => ''] as $name => $value) {
            Options::insert(['name' => $name, 'value' => $value, 'type' => 'string']);
        }

        return (string) $this->get('/')->assertOk()->getContent();
    }

    private function logoAfterMigration(string $logo): string
    {
        Options::where('name', 'game_logo')->delete();
        Options::insert(['name' => 'game_logo', 'value' => $logo, 'type' => 'string']);

        $migration = require database_path('migrations/400/2026_10_16_000030_use_local_game_logo.php');
        $this->assertInstanceOf(Migration::class, $migration);

        if (!method_exists($migration, 'up')) {
            $this->fail('the migration has no up()');
        }

        $migration->up();

        return Options::where('name', 'game_logo')->firstOrFail()->value;
    }
}
