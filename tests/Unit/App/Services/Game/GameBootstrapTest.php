<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Exceptions\LegacyView;
use App\Models\Ban;
use App\Models\User;
use App\Services\Game\GameBootstrap;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;
use App\Core\Enumerators\UserRanksEnumerator as UserRanks;

/**
 * Regression tests: the closed-server and ban checks and the game's time
 * zone only lived in the legacy bootstrap, which stopped running once every
 * game page was a native controller.
 */
#[CoversClass(GameBootstrap::class)]
class GameBootstrapTest extends DatabaseTestCase
{
    private string $defaultTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);

        parent::tearDown();
    }

    /**
     * @param array<string, string|int> $settings
     */
    private function bootstrap(array $settings): GameBootstrap
    {
        $settings += ['game_enable' => 1, 'close_reason' => 'Maintenance', 'date_time_zone' => 'UTC', 'game_name' => 'XGP', 'date_format_extended' => 'd.m.Y H:i:s'];

        foreach ($settings as $name => $value) {
            DB::table('options')->insert(['name' => $name, 'value' => (string) $value, 'type' => '32']);
        }

        return app(GameBootstrap::class);
    }

    private static function user(int $authlevel = UserRanks::PLAYER, ?Ban $ban = null): User
    {
        $user = new User();
        $user->authlevel = $authlevel;
        $user->setRelation('ban', $ban);

        return $user;
    }

    public function testTheGameTimezoneIsApplied(): void
    {
        $this->bootstrap(['date_time_zone' => 'Asia/Tokyo'])->applyTimezone();

        $this->assertSame('Asia/Tokyo', date_default_timezone_get());
    }

    public function testAnInvalidTimezoneIsIgnored(): void
    {
        date_default_timezone_set('UTC');

        $this->bootstrap(['date_time_zone' => 'Mars/Olympus'])->applyTimezone();

        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function testAnOpenServerLetsPlayersIn(): void
    {
        $this->bootstrap([])->ensureAccess(self::user());

        $this->addToAssertionCount(1);
    }

    public function testAClosedServerShowsTheReasonToPlayers(): void
    {
        $bootstrap = $this->bootstrap(['game_enable' => 0]);

        $this->expectException(LegacyView::class);

        $bootstrap->ensureAccess(self::user());
    }

    public function testAClosedServerLetsAdminsIn(): void
    {
        $this->bootstrap(['game_enable' => 0])->ensureAccess(self::user(UserRanks::ADMIN));

        $this->addToAssertionCount(1);
    }

    public function testABannedPlayerIsStopped(): void
    {
        $bootstrap = $this->bootstrap([]);

        $this->expectException(LegacyView::class);

        $bootstrap->ensureAccess(self::user(UserRanks::PLAYER, new Ban(['until' => time() + 3600])));
    }

    public function testAPermanentBanStopsThePlayer(): void
    {
        $bootstrap = $this->bootstrap([]);

        $this->expectException(LegacyView::class);

        $bootstrap->ensureAccess(self::user(UserRanks::PLAYER, new Ban(['until' => null])));
    }

    public function testAnExpiredBanLetsThePlayerIn(): void
    {
        $this->bootstrap([])->ensureAccess(self::user(UserRanks::PLAYER, new Ban(['until' => time() - 60])));

        $this->addToAssertionCount(1);
    }
}
