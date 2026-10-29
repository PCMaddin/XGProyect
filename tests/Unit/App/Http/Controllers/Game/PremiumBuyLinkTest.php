<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\PremiumController;
use App\Models\Options;
use Illuminate\Database\Migrations\Migration;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The "get dark matter" button pointed to xgproyect.org, where nothing can
 * be bought for this server. Without a shop URL of the server's own the
 * button is hidden; existing installs lose only the old default.
 */
#[CoversClass(PremiumController::class)]
class PremiumBuyLinkTest extends DatabaseTestCase
{
    public function testWithoutAShopTheButtonIsHidden(): void
    {
        $page = view('premium.buy', ['url' => ''])->render();

        $this->assertStringNotContainsString('darkmatter2', $page);
    }

    public function testTheShopOfTheServerIsLinked(): void
    {
        $page = view('premium.buy', ['url' => 'https://shop.example.org/dm'])->render();

        $this->assertStringContainsString("href='https://shop.example.org/dm'", $page);
    }

    public function testTheMigrationEmptiesOnlyTheOldDefault(): void
    {
        $this->assertSame('', $this->urlAfterMigration('https://www.xgproyect.org/game.php?page=premium'));
        $this->assertSame('', $this->urlAfterMigration('http://xgproyect.org/game.php?page=premium'));
        $this->assertSame('https://shop.example.org/dm', $this->urlAfterMigration('https://shop.example.org/dm'));
    }

    private function urlAfterMigration(string $url): string
    {
        Options::where('name', 'premium_url')->delete();
        Options::insert(['name' => 'premium_url', 'value' => $url, 'type' => 'string']);

        $migration = require database_path('migrations/400/2026_10_16_000032_clear_foreign_premium_url.php');
        $this->assertInstanceOf(Migration::class, $migration);

        if (!method_exists($migration, 'up')) {
            $this->fail('the migration has no up()');
        }

        $migration->up();

        $url = Options::where('name', 'premium_url')->value('value');
        $this->assertIsString($url);

        return $url;
    }
}
