<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Account;

use App\Http\Controllers\Account\PrivacyController;
use App\Libraries\Users;
use App\Models\User;
use App\Services\Account\AccountEraser;
use App\Services\Account\AccountExport;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\RegistrationService;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Tests\MysqlTestCase;

/**
 * A player downloads everything the universe keeps about the account, and
 * deletes the account at once: with it go the planets, messages, sign-ins
 * with IP addresses, sessions, suspicions and the WAX link; paid orders
 * stay for the books, the hall of fame keeps the place without the name.
 * Both ask for the password (and the code); other players keep their data.
 */
#[CoversClass(PrivacyController::class)]
#[CoversClass(AccountExport::class)]
#[CoversClass(AccountEraser::class)]
class PrivacyMysqlTest extends MysqlTestCase
{
    private const PASSWORD = 'secret-password';

    private User $player;

    private User $friend;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0',
            'database.connections.central' => config('database.connections.mysql_test'),
        ]);
        DB::purge('central');
        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);

        $this->player = $this->register('Leaver', 'leaver@example.com');
        $this->friend = $this->register('Friend', 'friend@example.com');
        $this->traces();
    }

    public function testTheDownloadHoldsTheAccountButNoSecrets(): void
    {
        $response = $this->asPlayer()->post('/account/privacy/export', ['password' => self::PASSWORD]);

        $response->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8');
        $this->assertStringContainsString('attachment; filename=xgproyect-Leaver-', (string) $response->headers->get('Content-Disposition'));
        $json = $response->streamedContent();
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        $this->assertSame('leaver@example.com', data_get($data, 'data.account.email'));
        $this->assertFalse(data_get($data, 'data.account.two_factor_enabled'));
        $planets = data_get($data, 'data.planets');
        $this->assertIsArray($planets);
        $this->assertCount(1, $planets);
        $this->assertSame(['Hello', 'Reply'], data_get($data, 'data.messages.*.message_subject'));
        $this->assertSame('203.0.113.7', data_get($data, 'data.login_events.0.ip'));
        $this->assertSame('leaverwax123', data_get($data, 'data.wax_links.0.wax_account'));
        $this->assertSame(1999, data_get($data, 'data.dark_matter_orders.0.amount'));
        $this->assertSame('Broken', data_get($data, 'data.bug_reports.0.description'));
        // never the password, the WAX link code, nor the findings of the cheat detection
        $this->assertStringNotContainsString(User::findOrFail($this->player->id)->password, $json);
        $this->assertFalse(Arr::hasAny($data, ['data.account.password', 'data.wax_links.0.code', 'data.suspicions']));
    }

    public function testWithoutThePasswordThereIsNoDownload(): void
    {
        $this->asPlayer()->post('/account/privacy/export', ['password' => 'wrong'])
            ->assertRedirect(route('account.privacy'))->assertSessionHas('error', __('account/privacy.pv_wrong_password'));
        $this->asPlayer()->post('/account/privacy/export')->assertRedirect(route('account.privacy'));
    }

    public function testTheAccountIsDeletedWithItsTracesAndTheOthersKeepTheirs(): void
    {
        $this->asPlayer()->get('/account/privacy')->assertOk()->assertSee(__('account/privacy.pv_delete_button'));

        $this->asPlayer()->post('/account/privacy/delete', ['password' => self::PASSWORD, 'confirm' => '1'])
            ->assertOk()->assertSee(__('account/privacy.pv_deleted_title'));

        $id = $this->player->id;
        $this->assertNull(DB::table('users')->find($id));
        $this->assertSame(0, DB::table('planets')->where('planet_user_id', $id)->count());
        $this->assertSame(0, DB::table('messages')->where('message_sender', $id)->orWhere('message_receiver', $id)->count());
        foreach (['login_events', 'sessions', 'activity_hours', 'user_items'] as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $id)->count(), $table);
        }
        $this->assertSame(0, DB::table('suspicions')->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame(0, DB::connection('central')->table('wax_links')->where('user_id', $id)->count());
        $this->assertSame('-', DB::connection('central')->table('season_results')->where('user_id', $id)->value('name'));
        $report = DB::connection('central')->table('bug_reports')->sole();
        $this->assertSame(['Broken', 0, '-', ''], [$report->description, (int) $report->user_id, $report->user_name, $report->user_agent]);
        // kept for the books, without anything else about the player
        $this->assertSame(1, DB::table('dark_matter_orders')->where('user_id', $id)->count());

        // the other player keeps everything of their own
        $this->assertNotNull(DB::table('users')->find($this->friend->id));
        $this->assertSame(1, DB::table('planets')->where('planet_user_id', $this->friend->id)->count());
        $this->assertSame(1, DB::table('login_events')->where('user_id', $this->friend->id)->count());
        $this->assertSame(1, DB::connection('central')->table('wax_links')->where('user_id', $this->friend->id)->count());
        $this->assertGuest();
    }

    public function testNothingIsDeletedWithoutPasswordConfirmationOrCode(): void
    {
        $this->asPlayer()->post('/account/privacy/delete', ['password' => 'wrong', 'confirm' => '1'])->assertRedirect(route('account.privacy'));
        $this->asPlayer()->post('/account/privacy/delete', ['password' => self::PASSWORD])->assertRedirect(route('account.privacy'));

        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $totp = new Totp();
        app(TwoFactor::class)->enable($this->player, $secret, $totp->code($secret, $totp->step(time() - 60)), time() - 60);
        $this->asPlayer()->post('/account/privacy/delete', ['password' => self::PASSWORD, 'confirm' => '1', 'code' => '000000'])->assertRedirect(route('account.privacy'));

        $this->assertNotNull(DB::table('users')->find($this->player->id));
        $this->assertSame(1, DB::table('login_events')->where('user_id', $this->player->id)->count());
    }

    public function testAnAdminCannotDeleteTheirOwnAccount(): void
    {
        DB::table('users')->where('id', $this->player->id)->update(['authlevel' => 1]);
        $this->player->refresh();

        $this->asPlayer()->post('/account/privacy/delete', ['password' => self::PASSWORD, 'confirm' => '1'])
            ->assertRedirect(route('account.privacy'))->assertSessionHas('error', __('account/privacy.pv_admin'));
        $this->assertNotNull(DB::table('users')->find($this->player->id));
    }

    private function register(string $name, string $email): User
    {
        $user = app(RegistrationService::class)->register(['name' => $name, 'email' => $email, 'password' => self::PASSWORD]);
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * What a few days of play leave behind, for both players.
     */
    private function traces(): void
    {
        $now = time();
        [$me, $other] = [$this->player->id, $this->friend->id];

        foreach ([$me, $other] as $id) {
            DB::table('login_events')->insert(['user_id' => $id, 'ip' => $id === $me ? '203.0.113.7' : '198.51.100.2', 'agent' => 'Firefox', 'device' => 'abc', 'created_at' => $now]);
            DB::connection('central')->table('wax_links')->insert(['universe' => '', 'user_id' => $id, 'code' => 'CODE' . $id, 'wax_account' => $id === $me ? 'leaverwax123' : 'friendwax123', 'created_at' => $now, 'linked_at' => $now]);
        }

        DB::table('messages')->insert([
            ['message_sender' => $me, 'message_receiver' => $other, 'message_time' => $now, 'message_type' => 4, 'message_from' => 'Leaver', 'message_subject' => 'Hello', 'message_text' => 'Hi', 'message_read' => 0],
            ['message_sender' => $other, 'message_receiver' => $me, 'message_time' => $now, 'message_type' => 4, 'message_from' => 'Friend', 'message_subject' => 'Reply', 'message_text' => 'Hey', 'message_read' => 0],
        ]);
        DB::table('activity_hours')->insert(['user_id' => $me, 'hour' => intdiv($now, 3600), 'requests' => 3]);
        DB::table('sessions')->insert(['id' => 'sess-leaver', 'user_id' => $me, 'ip_address' => '203.0.113.7', 'user_agent' => 'Firefox', 'payload' => '', 'last_activity' => $now]);
        DB::table('user_items')->insert(['user_id' => $me, 'item' => 'metal_booster_bronze']);
        DB::table('suspicions')->insert(['user_id' => $other, 'other_user' => $me, 'kind' => 'same_device', 'score' => 90, 'detail' => '', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('password_reset_tokens')->insert(['email' => 'leaver@example.com', 'token' => 'x']);
        DB::table('dark_matter_orders')->insert(['user_id' => $me, 'package' => 'dm_120k', 'dark_matter' => 120_000, 'amount' => 1999, 'currency' => 'eur', 'provider' => 'stripe', 'status' => 'paid', 'created_at' => $now, 'paid_at' => $now]);
        DB::connection('central')->table('season_results')->insert(['universe' => '', 'category' => 'total', 'rank' => 1, 'user_id' => $me, 'name' => 'Leaver', 'points' => 100, 'frozen_at' => $now]);
        DB::connection('central')->table('bug_reports')->insert(['universe' => '', 'user_id' => $me, 'user_name' => 'Leaver', 'page' => '/game.php', 'description' => 'Broken', 'user_agent' => 'Firefox', 'created_at' => $now, 'updated_at' => $now]);
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
}
