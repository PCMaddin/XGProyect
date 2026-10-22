<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Game;

use App\Http\Controllers\Game\PremiumController;
use App\Http\Controllers\Game\TraderResourcesController;
use App\Libraries\Users;
use App\Exceptions\NoLongerOnPlanet;
use App\Http\Controllers\Game\MovementController;
use App\Models\Fleets;
use App\Models\User;
use App\Services\Game\JumpGateService;
use App\Services\Game\MissileService;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\MysqlTestCase;

/**
 * Dark matter is bought with money: two requests at once must not both pay
 * from the same balance. Here another request spends the dark matter right
 * between the page's check and its payment.
 */
#[CoversClass(PremiumController::class)]
#[CoversClass(TraderResourcesController::class)]
class ConcurrentSpendingMysqlTest extends MysqlTestCase
{
    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);
        app(SettingsService::class)->write('premium_mode', 'pay2win');

        $player = app(RegistrationService::class)->register(['name' => 'Spender', 'email' => 'spender@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $player);
        $this->player = $player;
        DB::table('premium')->where('premium_user_id', $player->id)->update(['premium_dark_matter' => 100_000]);
    }

    public function testAnOfficerIsNotPaidFromDarkMatterSpentMeanwhile(): void
    {
        $this->spendBeforeThePayment('`premium_dark_matter` = `premium_dark_matter` - ?');

        $this->asPlayer()->post('/game.php?page=premium&offi=601&time=week');

        // the other request took it all: nothing left to pay, no officer
        $this->assertSame(0, $this->premium('premium_dark_matter'));
        $this->assertSame(0, $this->premium('premium_officier_commander'));
    }

    public function testAnOfficerBoughtTwiceAtOnceIsPaidOnce(): void
    {
        // another request hired the same officer between check and payment
        $this->interject('`premium_dark_matter` = `premium_dark_matter` - ?', function (): void {
            DB::table('premium')->where('premium_user_id', $this->player->id)->update([
                'premium_dark_matter' => DB::raw('premium_dark_matter - 1'),
                'premium_officier_commander' => time() + 604800,
            ]);
        });

        $this->asPlayer()->post('/game.php?page=premium&offi=601&time=week');

        $this->assertSame(99_999, $this->premium('premium_dark_matter'));
    }

    public function testARefillIsNotPaidFromDarkMatterSpentMeanwhile(): void
    {
        $this->spendBeforeThePayment('pr.`premium_dark_matter` = pr.`premium_dark_matter` - ?');
        $metal = self::number(DB::table('planets')->where('planet_user_id', $this->player->id)->value('planet_metal'));

        $this->asPlayer()->post('/game.php?page=traderResources', ['metal-10' => '1']);

        $this->assertSame(0, $this->premium('premium_dark_matter'));
        $this->assertSame($metal, self::number(DB::table('planets')->where('planet_user_id', $this->player->id)->value('planet_metal')));
    }

    public function testARefillAddsAndNeverRestoresCargoThatLeft(): void
    {
        DB::table('planets')->where('planet_user_id', $this->player->id)->update(['planet_metal' => 5000]);
        // a fleet leaves with 4000 metal while the refill is paid
        $this->interject('pr.`premium_dark_matter` = pr.`premium_dark_matter` - ?', function (): void {
            DB::table('planets')->where('planet_user_id', $this->player->id)->update(['planet_metal' => DB::raw('planet_metal - 4000')]);
        });

        $this->asPlayer()->post('/game.php?page=traderResources', ['metal-10' => '1']);

        $metal = self::number(DB::table('planets')->where('planet_user_id', $this->player->id)->value('planet_metal'));
        // 1000 left plus the refill, not 5000 plus the refill
        $this->assertGreaterThan(1000, $metal);
        $this->assertLessThan(5000, $metal);
    }

    public function testAShipyardOrderIsPaidFromWhatIsThereNow(): void
    {
        DB::table('buildings')->whereIn('building_planet_id', DB::table('planets')->where('planet_user_id', $this->player->id)->pluck('planet_id'))->update(['building_hangar' => 1]);
        DB::table('planets')->where('planet_user_id', $this->player->id)->update(['planet_metal' => 2000, 'planet_b_hangar_id' => '']);
        // all the metal leaves on a fleet while the order is paid
        $this->interject('CONCAT(p.`planet_b_hangar_id`', function (): void {
            DB::table('planets')->where('planet_user_id', $this->player->id)->update(['planet_metal' => 0]);
        });

        $this->asPlayer()->post('/game.php?page=defenses', ['fmenge' => [401 => 1]]);

        $planet = DB::table('planets')->where('planet_user_id', $this->player->id)->first(['planet_metal', 'planet_b_hangar_id']);
        $this->assertNotNull($planet);
        // no order, and the metal is not written back
        $this->assertSame([0, ''], [self::number($planet->planet_metal), (string) $planet->planet_b_hangar_id]);
    }

    public function testAFleetThatArrivedMeanwhileIsNotTurnedBack(): void
    {
        $planet = DB::table('planets')->where('planet_user_id', $this->player->id)->first();
        $this->assertNotNull($planet);
        $id = DB::table('fleets')->insertGetId([
            'fleet_owner' => $this->player->id, 'fleet_mission' => 3, 'fleet_amount' => 1, 'fleet_array' => serialize([202 => 1]),
            'fleet_start_time' => time() + 600, 'fleet_start_galaxy' => $planet->planet_galaxy, 'fleet_start_system' => $planet->planet_system,
            'fleet_start_planet' => $planet->planet_planet, 'fleet_start_type' => 1, 'fleet_end_time' => time() + 1200, 'fleet_end_stay' => 0,
            'fleet_end_galaxy' => $planet->planet_galaxy, 'fleet_end_system' => $planet->planet_system, 'fleet_end_planet' => $planet->planet_planet,
            'fleet_end_type' => 1, 'fleet_target_obj' => 0, 'fleet_resource_metal' => 500, 'fleet_resource_crystal' => 0, 'fleet_resource_deuterium' => 0,
            'fleet_fuel' => 0, 'fleet_target_owner' => $this->player->id, 'fleet_group' => '0', 'fleet_mess' => 0, 'fleet_creation' => time(),
        ]);
        $stale = Fleets::findOrFail($id);
        // meanwhile it arrived: cargo unloaded, on its way back
        DB::table('fleets')->where('fleet_id', $id)->update(['fleet_mess' => 1, 'fleet_resource_metal' => 0]);

        (new ReflectionMethod(MovementController::class, 'returnFleet'))->invoke(app(MovementController::class), $stale, $this->player->id);

        $fleet = DB::table('fleets')->where('fleet_id', $id)->first(['fleet_start_time', 'fleet_end_time']);
        $this->assertNotNull($fleet);
        $this->assertSame([time() + 600, time() + 1200], [self::number($fleet->fleet_start_time), self::number($fleet->fleet_end_time)]);
    }

    public function testAJumpGateMovesTheShipsOnce(): void
    {
        $origin = DB::table('planets')->where('planet_user_id', $this->player->id)->first();
        $this->assertNotNull($origin);
        $target = (array) $origin;
        unset($target['planet_id']);
        $target['planet_planet'] = (int) $origin->planet_planet + 1;
        $targetId = (int) DB::table('planets')->insertGetId($target);
        DB::table('ships')->insert(['ship_planet_id' => $targetId]);
        DB::table('ships')->where('ship_planet_id', $origin->planet_id)->update(['ship_small_cargo_ship' => 10]);

        $gate = app(JumpGateService::class);
        $state = static function (string $name, mixed $value) use ($gate): void {
            (new ReflectionProperty(JumpGateService::class, $name))->setValue($gate, $value);
        };
        $transfer = new ReflectionMethod(JumpGateService::class, 'transfer');
        $state('user', ['id' => $this->player->id]);

        // two jumps from pages read at the same moment
        foreach ([1, 2] as $jump) {
            $state('planet', ['planet_id' => $origin->planet_id, 'planet_last_jump_time' => 0]);

            try {
                $transfer->invoke($gate, ['ship_small_cargo_ship' => 10], $targetId, 0);
            } catch (NoLongerOnPlanet) {
                $this->assertSame(2, $jump);
            }
        }

        $this->assertSame(0, self::number(DB::table('ships')->where('ship_planet_id', $origin->planet_id)->value('ship_small_cargo_ship')));
        $this->assertSame(10, self::number(DB::table('ships')->where('ship_planet_id', $targetId)->value('ship_small_cargo_ship')));
    }

    public function testMissilesAreFiredOnce(): void
    {
        $planetId = self::number(DB::table('planets')->where('planet_user_id', $this->player->id)->value('planet_id'));
        DB::table('defenses')->where('defense_planet_id', $planetId)->update(['defense_interplanetary_missile' => 5]);
        $launch = fn () => app(MissileService::class)->launch($this->player->id, $planetId, ['galaxy' => 1, 'system' => 1, 'planet' => 1], ['galaxy' => 1, 'system' => 2, 'planet' => 1], 5, 0, 99, time());

        $launch();

        try {
            // the same five again, from a page read before
            $launch();
            $this->fail('fired twice');
        } catch (NoLongerOnPlanet) {
            $this->assertSame(0, self::number(DB::table('defenses')->where('defense_planet_id', $planetId)->value('defense_interplanetary_missile')));
            $this->assertSame(1, DB::table('fleets')->where('fleet_owner', $this->player->id)->count());
        }
    }

    private function premium(string $column): int
    {
        return self::number(DB::table('premium')->where('premium_user_id', $this->player->id)->value($column));
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function asPlayer(): static
    {
        (new ReflectionProperty(Users::class, 'instance'))->setValue(null, null);
        $password = User::findOrFail($this->player->id)->getAuthPassword();

        return $this->actingAs($this->player)->withSession([
            'user_id' => $this->player->id,
            'user_password' => Hash::make($password . '-test-secret'),
        ]);
    }

    private function spendBeforeThePayment(string $payment): void
    {
        $this->interject($payment, function (): void {
            DB::table('premium')->where('premium_user_id', $this->player->id)->update(['premium_dark_matter' => 0]);
        });
    }

    /**
     * Runs $other once, right before the first query that holds $sql.
     */
    private function interject(string $sql, callable $other): void
    {
        $done = false;
        DB::connection()->beforeExecuting(function (string $query) use ($sql, $other, &$done): void {
            if (!$done && str_contains($query, $sql)) {
                $done = true;
                $other();
            }
        });
    }
}
