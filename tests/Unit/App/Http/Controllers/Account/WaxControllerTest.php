<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Account;

use App\Http\Controllers\Account\WaxController;
use App\Models\User;
use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\SeasonRewards;
use App\Services\Premium\WaxLinks;
use App\Services\SettingsService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * A player links a WAX account and redeems the prizes it won in past
 * seasons as dark matter in a Pay2Win universe; each prize once, and only
 * the prizes of that WAX account.
 */
#[CoversClass(WaxController::class)]
#[CoversClass(SeasonRewards::class)]
#[CoversClass(WaxLinks::class)]
class WaxControllerTest extends DatabaseTestCase
{
    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'premium.wax.account' => 'xgpshop']);
        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        app(SettingsService::class)->write('premium_mode', 'pay2win');

        $player = new User(['name' => 'Player', 'email' => 'player@example.com', 'password' => 'secret-password', 'home_planet_id' => 1, 'current_planet' => 1]);
        $player->save();
        $this->player = $player;
        DB::table('premium')->insert(['premium_user_id' => $player->id, 'premium_dark_matter' => 100]);

        // the player plays in "next"; won prizes in "old"
        $context = $this->createStub(InstanceContext::class);
        $context->method('current')->willReturn(new Instance('next', 'Next', 'xgp_next', Instance::PAY2WIN, Instance::OPEN, time()));
        $this->app->instance(InstanceContext::class, $context);

        DB::connection('central')->table('season_results')->insert([
            $this->prize(1, 'total', 1, 30_000, 'player.wam'),
            $this->prize(2, 'military', 3, 3_000, 'player.wam'),
            $this->prize(3, 'total', 2, 20_000, 'rival.wam'),
        ]);
    }

    public function testWithoutALinkThePageShowsTheMemoToSend(): void
    {
        $page = $this->actingAs($this->player)->get('/account/wax')->assertOk()->assertSee('not linked')->assertSee('xgpshop');

        $code = DB::connection('central')->table('wax_links')->where('user_id', $this->player->id)->value('code');
        $this->assertIsString($code);
        $page->assertSee(WaxLinks::MEMO_PREFIX . $code);
        $this->assertSame('next', DB::connection('central')->table('wax_links')->where('user_id', $this->player->id)->value('universe'));

        $this->actingAs($this->player)->post('/account/wax/renew')->assertSessionHas('success');
        $this->assertNotSame($code, DB::connection('central')->table('wax_links')->where('user_id', $this->player->id)->value('code'));
    }

    public function testTheLinkedAccountsPrizesAreRedeemedOnce(): void
    {
        $this->link('player.wam');

        $this->actingAs($this->player)->get('/account/wax')->assertOk()
            ->assertSee('linked to player.wam')->assertSee('30.000')->assertSee('3.000')->assertDontSee('20.000');

        $this->actingAs($this->player)->post('/account/wax/claim', ['reward' => 1])->assertSessionHas('success');
        $this->assertSame(30_100, $this->darkMatter());
        $this->assertSame('next', DB::connection('central')->table('season_results')->where('id', 1)->value('claimed_universe'));

        // once only, and never another account's prize
        $this->actingAs($this->player)->post('/account/wax/claim', ['reward' => 1])->assertSessionHas('error');
        $this->actingAs($this->player)->post('/account/wax/claim', ['reward' => 3])->assertSessionHas('error');
        $this->assertSame(30_100, $this->darkMatter());
        $this->assertNull(DB::connection('central')->table('season_results')->where('id', 3)->value('claimed_at'));
    }

    public function testPrizesAreRedeemedInPay2WinUniversesOnly(): void
    {
        $this->link('player.wam');
        app(SettingsService::class)->write('premium_mode', 'play2win');

        $this->actingAs($this->player)->get('/account/wax')->assertOk()->assertSee('You redeem season prizes in a Pay2Win universe.')->assertDontSee('>Redeem<', false);
        $this->actingAs($this->player)->post('/account/wax/claim', ['reward' => 1])->assertSessionHas('error');
        $this->assertSame(100, $this->darkMatter());
    }

    public function testAPrizeWaitsAgainWhenItCannotBeCredited(): void
    {
        $this->link('player.wam');
        DB::table('premium')->where('premium_user_id', $this->player->id)->delete();

        $this->assertSame(0, app(SeasonRewards::class)->claim(1, 'player.wam', 'next', $this->player->id, time()));
        $this->assertNull(DB::connection('central')->table('season_results')->where('id', 1)->value('claimed_at'));
    }

    private function link(string $account): void
    {
        $links = app(WaxLinks::class);
        $code = $links->code('next', $this->player->id, time());
        $this->assertTrue($links->link(WaxLinks::MEMO_PREFIX . $code, $account, time()));
    }

    private function darkMatter(): int
    {
        $value = DB::table('premium')->where('premium_user_id', $this->player->id)->value('premium_dark_matter');

        return is_numeric($value) ? (int) $value : -1;
    }

    /**
     * @return array<string, mixed>
     */
    private function prize(int $id, string $category, int $rank, int $reward, string $account): array
    {
        return [
            'id' => $id, 'universe' => 'old', 'category' => $category, 'rank' => $rank, 'user_id' => 99, 'name' => 'Player',
            'alliance_tag' => null, 'points' => 1000.0, 'frozen_at' => time() - 86400, 'reward' => $reward, 'wax_account' => $account,
        ];
    }
}
