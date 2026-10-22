<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Game;

use App\Http\Controllers\Game\AllianceController;
use App\Http\Controllers\Game\ChatController;
use App\Http\Controllers\Game\GalaxyController;
use App\Http\Controllers\Game\HighscoreController;
use App\Http\Controllers\Game\OverviewController;
use App\Libraries\GalaxyLib;
use App\Libraries\Users;
use App\Models\User;
use App\Services\RegistrationService;
use App\Support\Html;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Tests\MysqlTestCase;

/**
 * Names of players, planets and alliances are shown on many pages, often in
 * HTML built as strings and in tooltips (a script string inside an
 * attribute). A name with markup, set by an admin or left from an older
 * version, must stay text everywhere: no element, no quote that ends a
 * string.
 */
#[CoversClass(GalaxyLib::class)]
#[CoversClass(GalaxyController::class)]
#[CoversClass(HighscoreController::class)]
#[CoversClass(AllianceController::class)]
#[CoversClass(OverviewController::class)]
#[CoversClass(ChatController::class)]
#[CoversClass(Html::class)]
class StoredXssMysqlTest extends MysqlTestCase
{
    private const PAYLOAD = 'x"\'><img src=x onerror=a()>\x3c';

    private User $player;

    private User $neighbour;

    /** @var array{galaxy: int, system: int, planet: int} */
    private array $home;

    protected function setUp(): void
    {
        parent::setUp();

        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'version.files' => '4.0.0']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
        DB::table('options')->whereIn('name', ['last_cleanup', 'last_backup', 'stat_last_update'])->update(['value' => (string) time()]);

        $this->player = $this->register('Player');
        $this->neighbour = $this->register('Neighbour');

        $home = DB::table('planets')->where('planet_user_id', $this->player->id)->first(['planet_galaxy', 'planet_system', 'planet_planet']);
        $this->assertNotNull($home);
        $this->home = ['galaxy' => (int) $home->planet_galaxy, 'system' => (int) $home->planet_system, 'planet' => (int) $home->planet_planet];
        $position = $this->home['planet'] === 15 ? 14 : $this->home['planet'] + 1;

        // what an admin could set before (or an older version let through)
        DB::table('planets')->where('planet_user_id', $this->neighbour->id)->update(['planet_galaxy' => $this->home['galaxy'], 'planet_system' => $this->home['system'], 'planet_planet' => $position, 'planet_name' => self::PAYLOAD]);
        DB::table('planets')->where('planet_user_id', $this->player->id)->update(['planet_name' => self::PAYLOAD]);
        $alliance = DB::table('alliance')->insertGetId(['alliance_name' => self::PAYLOAD, 'alliance_tag' => 'x"\'><b>', 'alliance_owner' => $this->player->id, 'alliance_register_time' => time(), 'alliance_ranks' => '[]']);
        DB::table('alliance_statistics')->insert(['alliance_statistic_alliance_id' => $alliance]);
        DB::table('users')->whereIn('id', [$this->player->id, $this->neighbour->id])->update(['ally_id' => $alliance, 'ally_rank_id' => 0]);
        // inactive: the galaxy shows the name with a status
        DB::table('users')->where('id', $this->neighbour->id)->update(['name' => self::PAYLOAD, 'onlinetime' => time() - 14 * 86400]);
        DB::table('users_statistics')->update(['user_statistic_total_rank' => 1]);
    }

    public function testTheGalaxyTooltipsKeepNamesAsText(): void
    {
        $page = $this->page('galaxy&galaxy=' . $this->home['galaxy'] . '&system=' . $this->home['system']);

        $this->assertNoMarkup($page);
        // inside the tooltip's script string: escaped for the HTML it shows
        $this->assertStringContainsString('&lt;img src=x', $this->decodedAttributes($page, 'onmouseover'));
        $this->assertStringNotContainsString("x\"'", $this->decodedAttributes($page, 'onmouseover'));
        // \x3c would have become "<" in the script string
        $this->assertStringContainsString('\\\\x3c', $this->decodedAttributes($page, 'onmouseover'));
    }

    public function testTheHighscoreTheAllianceAndTheChatKeepNamesAsText(): void
    {
        $this->assertNoMarkup($this->page('highscore'));
        $this->assertNoMarkup($this->page('alliance'));
        $this->assertNoMarkup($this->page('chat&playerId=' . $this->neighbour->id));

        $members = $this->page('alliance&mode=admin&edit=members');
        $this->assertNoMarkup($members);
        // the kick confirmation: the name is a script string, its quotes escaped
        $this->assertStringContainsString('x\\"\\\'', $this->decodedAttributes($members, 'onclick'));
    }

    public function testTheOverviewKeepsThePlanetNameAsText(): void
    {
        $page = $this->page('overview');

        $this->assertNoMarkup($page);
        $this->assertStringNotContainsString("x\"'", $this->decodedAttributes($page, 'onclick'));
    }

    public function testAMessageSentByAPlayerWithSuchANameKeepsItAsText(): void
    {
        DB::table('users')->where('id', $this->player->id)->update(['name' => self::PAYLOAD]);

        $this->asPlayer()->post('/game.php?page=chat&playerId=' . $this->neighbour->id, ['subject' => '<b>Hi</b>', 'text' => '<script>x</script>']);

        $message = DB::table('messages')->where('message_receiver', $this->neighbour->id)->where('message_sender', $this->player->id)->first();
        $this->assertNotNull($message);
        // messages are shown as HTML: only the coordinates' link stays markup
        $this->assertDoesNotMatchRegularExpression('/<(img|b|script)\b/', (string) $message->message_from . $message->message_subject . $message->message_text);
        $this->assertStringContainsString('&lt;img src=x', (string) $message->message_from);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $page
     */
    private function assertNoMarkup(TestResponse $page): void
    {
        $page->assertOk();
        $this->assertStringNotContainsString('<img src=x', (string) $page->getContent());
        $this->assertStringNotContainsString('<b>', (string) $page->getContent());
    }

    /**
     * The values of all attributes of that name, decoded as the browser
     * hands them to the script.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $page
     */
    private function decodedAttributes(TestResponse $page, string $attribute): string
    {
        preg_match_all('/\s' . $attribute . '="([^"]*)"/', (string) $page->getContent(), $matches);

        return html_entity_decode(implode("\n", $matches[1]), ENT_QUOTES | ENT_HTML5);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function page(string $query): TestResponse
    {
        return $this->asPlayer()->get('/game.php?page=' . $query);
    }

    private function register(string $name): User
    {
        $user = app(RegistrationService::class)->register(['name' => $name, 'email' => strtolower($name) . '@example.com', 'password' => 'secret-password']);
        $this->assertInstanceOf(User::class, $user);

        return User::findOrFail($user->id);
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
