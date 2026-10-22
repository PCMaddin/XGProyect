<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\NoobsProtectionLib;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

#[CoversClass(NoobsProtectionLib::class)]
class NoobsProtectionLibTest extends DatabaseTestCase
{
    private function seedSettings(int $protection, int $multi = 5, int $time = 0, int $adminLevel = 3): void
    {
        DB::table('options')->insert([
            ['name' => 'noobprotection', 'value' => (string) $protection, 'type' => '32'],
            ['name' => 'noobprotectionmulti', 'value' => (string) $multi, 'type' => '32'],
            ['name' => 'noobprotectiontime', 'value' => (string) $time, 'type' => '32'],
            ['name' => 'stat_admin_level', 'value' => (string) $adminLevel, 'type' => '32'],
        ]);
    }

    public function testWeakWhenAttackerFarOutranksTarget(): void
    {
        $this->seedSettings(protection: 1, multi: 5);
        $noob = new NoobsProtectionLib();

        // 1000 > 100 * 5  -> attacker too strong for a weak target
        $this->assertTrue($noob->isWeak(1000, 100));
        $this->assertFalse($noob->isWeak(100, 1000));
    }

    public function testStrongWhenAttackerFarBelowTarget(): void
    {
        $this->seedSettings(protection: 1, multi: 5);
        $noob = new NoobsProtectionLib();

        // 100 * 5 < 1000  -> attacker too weak to hit a strong target
        $this->assertTrue($noob->isStrong(100, 1000));
        $this->assertFalse($noob->isStrong(1000, 100));
    }

    public function testProtectionDisabledMeansNeverWeakOrStrong(): void
    {
        $this->seedSettings(protection: 0, multi: 5);
        $noob = new NoobsProtectionLib();

        $this->assertFalse($noob->isWeak(1000, 100));
        $this->assertFalse($noob->isStrong(100, 1000));
    }

    public function testActiveTargetAboveTheTimeThresholdIsNotWeak(): void
    {
        // target has more points than the protection time threshold -> excluded
        $this->seedSettings(protection: 1, multi: 5, time: 50);
        $noob = new NoobsProtectionLib();

        $this->assertFalse($noob->isWeak(1000, 100));
    }

    public function testRankVisibilityFollowsTheAdminLevel(): void
    {
        $this->seedSettings(protection: 1, adminLevel: 3);
        $noob = new NoobsProtectionLib();

        $this->assertTrue($noob->isRankVisible(2));
        $this->assertFalse($noob->isRankVisible(5));
    }
}
