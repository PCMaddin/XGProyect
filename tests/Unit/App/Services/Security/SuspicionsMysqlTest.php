<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Security;

use App\Services\Security\CheatScanner;
use App\Services\Security\PlayerTrail;
use App\Services\Security\Suspicions;
use App\Models\User;
use App\Services\RegistrationService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * The scan and the admin's list on MariaDB, with the table prefix the game
 * runs with (aliases in raw SQL broke the list there).
 */
#[CoversClass(Suspicions::class)]
#[CoversClass(CheatScanner::class)]
class SuspicionsMysqlTest extends MysqlTestCase
{
    public function testTheScanFindsAndTheListSortsTheSigns(): void
    {
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        $alpha = $this->player('Alpha');
        $beta = $this->player('Beta');
        $gamma = $this->player('Gamma');

        $trail = app(PlayerTrail::class);
        $trail->login($alpha, '10.0.0.1', 'Firefox', str_repeat('a', 32), time() - 60);
        $trail->login($beta, '10.0.0.2', 'Firefox', str_repeat('a', 32), time() - 60);
        $trail->login($gamma, '10.0.0.1', 'Chrome', str_repeat('c', 32), time() - 60);
        $this->assertSame(4, app(CheatScanner::class)->scan(time()));

        $suspicions = app(Suspicions::class);
        $gammaSign = DB::table('suspicions')->where('user_id', $gamma)->value('id');
        $this->assertIsNumeric($gammaSign);
        $suspicions->decide((int) $gammaSign, Suspicions::CLEARED, '', time());

        $list = $suspicions->all();
        $this->assertSame(['Alpha', 'Beta', 'Alpha', 'Gamma'], array_column($list, 'user_name'));
        $this->assertSame(['shared_device', 'shared_device', 'shared_ip', 'shared_ip'], array_column($list, 'kind'));
        $this->assertSame('cleared', $list[3]['status']);
        $this->assertSame('Beta', $list[0]['other_name']);
    }

    private function player(string $name): int
    {
        $user = app(RegistrationService::class)->register(['name' => $name, 'email' => strtolower($name) . '@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $user);

        return $user->id;
    }
}
