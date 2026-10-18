<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Game;

use App\Http\Controllers\Game\PremiumController;
use App\Http\Controllers\Game\TraderResourcesController;
use App\Libraries\Users;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
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
