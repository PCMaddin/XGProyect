<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;
use Tests\DatabaseTestCase;

/**
 * Settings read with SettingsService must exist, or the page reading them
 * fails. A new install gets them from OptionsTableSeeder, an existing one
 * from the update migrations, so every seeded option must have one.
 */
class OptionMigrationsTest extends DatabaseTestCase
{
    public function testEverySeededOptionReachesExistingInstalls(): void
    {
        $seeder = (string) file_get_contents(database_path('seeders/OptionsTableSeeder.php'));
        $migrations = implode('', array_map(
            static fn (string $file): string => (string) file_get_contents($file),
            glob(database_path('migrations/400/*.php')) ?: []
        ));

        preg_match_all("/'name' => '([^']+)'/", $seeder, $names);
        $this->assertNotEmpty($names[1]);

        foreach ($names[1] as $name) {
            $this->assertStringContainsString("'" . $name . "'", $migrations, $name . ' is seeded but no update migration adds it');
        }
    }

    public function testTheBotAttackOptionIsAddedOnceAndOff(): void
    {
        Options::where('name', 'bots_attack_players')->delete();

        $migration = require database_path('migrations/400/2026_10_16_000031_add_bots_attack_players_option.php');
        $this->assertInstanceOf(Migration::class, $migration);

        if (!method_exists($migration, 'up')) {
            $this->fail('the migration has no up()');
        }

        $migration->up();
        $migration->up();

        $this->assertSame(['0'], Options::where('name', 'bots_attack_players')->pluck('value')->all());
    }
}
