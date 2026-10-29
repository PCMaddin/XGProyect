<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\UpdatesLibrary;
use App\Models\User;
use App\Services\RegistrationService;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * A booster (item) raises the mines' production of its resource for the
 * time it works, and the per-hour value shows it while it works.
 */
#[CoversClass(UpdatesLibrary::class)]
class BoosterProductionMysqlTest extends MysqlTestCase
{
    private const HOUR = 3600;

    private int $planetId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['version.files' => '4.0.0']);
        $this->seed(OptionsTableSeeder::class);

        $player = app(RegistrationService::class)->register(['name' => 'Miner', 'email' => 'miner@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $player);
        $this->planetId = (int) $player->fresh()?->home_planet_id;

        DB::table('buildings')->where('building_planet_id', $this->planetId)->update(['building_metal_mine' => 15, 'building_solar_plant' => 20, 'building_metal_store' => 10]);
    }

    public function testABoosterRaisesTheMineProductionForTheTimeItWorks(): void
    {
        $now = time();
        $plain = $this->produce($now);

        // 30 % for the whole hour
        DB::table('planet_boosters')->insert(['planet_id' => $this->planetId, 'resource' => 'metal', 'percent' => 30, 'starts_at' => $now - 2 * self::HOUR, 'ends_at' => $now + self::HOUR]);
        $boosted = $this->produce($now);

        $boost = $boosted['perhour'] - $plain['perhour'];
        $this->assertGreaterThan(0, $boost);
        $this->assertEqualsWithDelta($boost, $boosted['gain'] - $plain['gain'], 1.0);

        // worked only the first half of the hour: half the extra, not shown any more
        DB::table('planet_boosters')->update(['ends_at' => $now - self::HOUR / 2]);
        $half = $this->produce($now);
        $this->assertSame($plain['perhour'], $half['perhour']);
        $this->assertEqualsWithDelta($boost / 2, $half['gain'] - $plain['gain'], 1.0);
    }

    /**
     * One hour of production, simulated.
     *
     * @return array{perhour: float, gain: float}
     */
    private function produce(int $now): array
    {
        $planet = (array) DB::table('planets AS p')
            ->join('buildings AS b', 'b.building_planet_id', '=', 'p.planet_id')
            ->join('ships AS s', 's.ship_planet_id', '=', 'p.planet_id')
            ->join('defenses AS d', 'd.defense_planet_id', '=', 'p.planet_id')
            ->where('p.planet_id', $this->planetId)
            ->first();
        $planet['planet_metal'] = 0;
        $planet['planet_last_update'] = $now - self::HOUR;
        $planet['planet_energy_max'] = 1;
        $user = (array) DB::table('users AS u')
            ->join('research AS r', 'r.research_user_id', '=', 'u.id')
            ->join('premium AS pr', 'pr.premium_user_id', '=', 'u.id')
            ->join('preferences AS pf', 'pf.preference_user_id', '=', 'u.id')
            ->where('u.id', $planet['planet_user_id'])
            ->first();

        UpdatesLibrary::updatePlanetResources($user, $planet, $now, true);

        $this->assertIsNumeric($planet['planet_metal_perhour']);
        $this->assertIsNumeric($planet['planet_metal']);

        return ['perhour' => (float) $planet['planet_metal_perhour'], 'gain' => (float) $planet['planet_metal']];
    }
}
