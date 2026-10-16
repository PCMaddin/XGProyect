<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game\Bots;

use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\DefensesEnumerator as Defenses;
use App\Core\Enumerators\ResearchEnumerator as Research;
use App\Libraries\UpdatesLibrary;
use App\Models\User;
use App\Services\Admin\BotService;
use App\Services\Game\Bots\BotPlayerService;
use App\Services\SettingsService;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Testing\Fakes\ExceptionHandlerFake;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\MysqlTestCase;

/**
 * A bot's turn on a real database: it brings its planet up to date, queues
 * a building, research and defences through the players' queues, stays
 * online and waits 10 to 20 minutes; the switch and the one-minute gap
 * hold it back; the cleanup leaves idle bots alone.
 */
#[CoversClass(BotPlayerService::class)]
#[CoversClass(BotService::class)]
class BotPlayerMysqlTest extends MysqlTestCase
{
    private int $bot;

    private int $planet;

    protected function setUp(): void
    {
        parent::setUp();

        config(['version.files' => '4.0.0']);
        // a bot turn reports its errors instead of failing: none may occur
        Exceptions::fake();
        $this->seed(OptionsTableSeeder::class);

        $this->assertSame(1, app(BotService::class)->createBots(1, 1, 1));

        $bot = User::where('user_is_bot', true)->firstOrFail();
        $this->bot = $bot->id;
        $this->planet = $bot->home_planet_id;
    }

    protected function tearDown(): void
    {
        // skipped without MariaDB: nothing was faked
        if (Exceptions::getFacadeRoot() instanceof ExceptionHandlerFake) {
            Exceptions::assertNothingReported();
        }

        parent::tearDown();
    }

    public function testABotBuildsResearchesAndDefends(): void
    {
        DB::table('buildings')->where('building_planet_id', $this->planet)->update([
            'building_metal_mine' => 4, 'building_crystal_mine' => 3, 'building_solar_plant' => 6,
            'building_hangar' => 1, 'building_laboratory' => 1,
        ]);
        DB::table('planets')->where('planet_id', $this->planet)->update([
            'planet_metal' => 60000, 'planet_crystal' => 30000, 'planet_deuterium' => 10000, 'planet_last_update' => time(),
        ]);
        $now = time();

        app(BotPlayerService::class)->play($this->bot, $now);

        $building = DB::table('building_queues')->where('planet_id', $this->planet)->value('building_id');
        $this->assertIsNumeric($building);
        $this->assertEquals(Research::RESEARCH_ENERGY_TECHNOLOGY, DB::table('research_queues')->where('user_id', $this->bot)->value('tech_id'));
        $hangar = DB::table('planets')->where('planet_id', $this->planet)->value('planet_b_hangar_id');
        $this->assertIsString($hangar);
        $this->assertStringStartsWith(Defenses::DEFENSE_ROCKET_LAUNCHER . ',', $hangar);

        $bot = User::findOrFail($this->bot);
        $this->assertSame($now, $bot->onlinetime);
        $this->assertGreaterThanOrEqual($now + 600, $bot->user_bot_next_action);
        $this->assertLessThanOrEqual($now + 1200, $bot->user_bot_next_action);

        // nothing went below zero: everything was paid for
        $stock = DB::table('planets')->where('planet_id', $this->planet)->first();
        $this->assertNotNull($stock);
        $this->assertGreaterThanOrEqual(0, (float) $stock->planet_metal);
        $this->assertGreaterThanOrEqual(0, (float) $stock->planet_crystal);
    }

    public function testANewBotStartsWithItsSolarPlant(): void
    {
        DB::table('planets')->where('planet_id', $this->planet)->update(['planet_metal' => 500, 'planet_crystal' => 500, 'planet_last_update' => time()]);

        app(BotPlayerService::class)->play($this->bot, time());

        $this->assertEquals(Buildings::BUILDING_SOLAR_PLANT, DB::table('building_queues')->where('planet_id', $this->planet)->value('building_id'));
        $this->assertSame(0, DB::table('research_queues')->where('user_id', $this->bot)->count());
    }

    public function testTheSwitchAndTheGapHoldBotsBack(): void
    {
        $settings = app(SettingsService::class);
        User::where('id', $this->bot)->update(['user_bot_next_action' => 0]);

        $settings->write('bots_play', 0);
        app(BotPlayerService::class)->playDue(time());
        $this->assertSame(0, DB::table('building_queues')->count());

        $settings->write('bots_play', 1);
        $settings->write('bots_last_run', time() - 30);
        app(BotPlayerService::class)->playDue(time());
        $this->assertSame(0, DB::table('building_queues')->count());

        $settings->write('bots_last_run', time() - 61);
        app(BotPlayerService::class)->playDue(time());
        $this->assertSame(1, DB::table('building_queues')->count());

        // the bot waits until its next action
        DB::table('building_queues')->delete();
        $settings->write('bots_last_run', 0);
        app(BotPlayerService::class)->playDue(time());
        $this->assertSame(0, DB::table('building_queues')->count());
    }

    public function testTheCleanupDoesNotDeleteIdleBots(): void
    {
        $longAgo = time() - 60 * 24 * 3600;
        User::where('id', $this->bot)->update(['onlinetime' => $longAgo, 'user_bot_next_action' => time() + 3600]);
        app(SettingsService::class)->write('last_cleanup', 0);

        new UpdatesLibrary();

        $this->assertTrue(User::where('id', $this->bot)->exists());
    }
}
