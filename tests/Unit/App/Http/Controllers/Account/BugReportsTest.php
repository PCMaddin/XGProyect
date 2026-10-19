<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Account;

use App\Http\Controllers\Account\ReportController;
use App\Http\Controllers\Operator\ReportsController;
use App\Models\Operator;
use App\Models\User;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\Feedback\BugReports;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * A player reports a problem from any game page; the report keeps the page
 * (only a path of this site), the player and the browser, and lands in the
 * base database for the operators, who set it to seen and fixed.
 */
#[CoversClass(ReportController::class)]
#[CoversClass(ReportsController::class)]
#[CoversClass(BugReports::class)]
class BugReportsTest extends DatabaseTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'operators.web' => true, 'operators.allowed_ips' => '']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);

        $player = new User(['name' => 'Reporter', 'email' => 'reporter@example.com', 'password' => 'secret-password', 'home_planet_id' => 1, 'current_planet' => 1]);
        $player->save();
        $this->player = $player;
    }

    public function testAPlayerReportsAProblemFromAGamePage(): void
    {
        $this->actingAs($this->player)->get('/account/report?from=' . urlencode('/game.php?page=fleet1&galaxy=1'))
            ->assertOk()->assertSee('/game.php?page=fleet1&amp;galaxy=1', false);

        $this->actingAs($this->player)
            ->post('/account/report', ['from' => '/game.php?page=fleet1&galaxy=1', 'description' => 'The fleet page shows no ships after sending.'], ['User-Agent' => 'Firefox/130'])
            ->assertRedirect()->assertSessionHas('report_sent', true);

        $report = DB::connection('central')->table('bug_reports')->sole();
        $this->assertSame('/game.php?page=fleet1&galaxy=1', $report->page);
        $this->assertSame('Reporter', $report->user_name);
        $this->assertSame($this->player->id, (int) $report->user_id);
        $this->assertSame('Firefox/130', $report->user_agent);
        $this->assertSame('new', $report->status);
    }

    public function testOnlyAPathOfThisSiteIsKeptAsThePage(): void
    {
        $this->assertSame('', BugReports::page('https://evil.example/phish'));
        $this->assertSame('', BugReports::page('//evil.example/x'));
        $this->assertSame('', BugReports::page('javascript:alert(1)'));
        $this->assertSame('/game.php?page=overview', BugReports::page('/game.php?page=overview'));
    }

    public function testAShortReportOrAGuestIsRefused(): void
    {
        $this->actingAs($this->player)->post('/account/report', ['description' => 'broken'])->assertSessionHasErrors('description');
        $this->app['auth']->forgetGuards();
        $this->post('/account/report', ['description' => 'Something long enough to count.'])->assertRedirect();

        $this->assertSame(0, DB::connection('central')->table('bug_reports')->count());
    }

    public function testTheOperatorsSeeTheReportsAndSetThemToFixed(): void
    {
        $reports = app(BugReports::class);
        $first = $reports->submit($this->player, '/game.php?page=galaxy', 'Galaxy view is empty.', 'Chrome', time() - 60);
        $reports->submit($this->player, '/game.php?page=research', 'Research <b>queue</b> stuck.', 'Chrome', time());

        $this->signInAsOperator();
        $this->get('/operator/reports')->assertOk()
            ->assertSeeInOrder(['Research &lt;b&gt;queue&lt;/b&gt; stuck.', 'Galaxy view is empty.'], false);

        $this->from('/operator/reports')->post('/operator/reports/' . $first, ['status' => 'fixed'])->assertRedirect('/operator/reports');
        $this->assertSame(['new' => 1, 'seen' => 0, 'fixed' => 1], $reports->counts());
        $this->get('/operator/reports?status=fixed')->assertOk()->assertSee('Galaxy view is empty.')->assertDontSee('Research');

        $this->from('/operator/reports')->post('/operator/reports/' . $first, ['status' => 'deleted'])->assertSessionHasErrors('status');
    }

    public function testOnlyOperatorsSeeTheReports(): void
    {
        $this->get('/operator/reports')->assertRedirect(route('operator.login'));
        $this->actingAs($this->player)->get('/operator/reports')->assertRedirect(route('operator.login'));
    }

    private function signInAsOperator(): void
    {
        $totp = new Totp();
        $operator = new Operator(['email' => 'ops@example.com', 'password' => 'correct horse battery', 'created_at' => time()]);
        app(TwoFactor::class)->enable($operator, self::SECRET, $totp->code(self::SECRET, $totp->step(time() - 60)), time() - 60);

        $this->post('/operator/login', ['email' => 'ops@example.com', 'password' => 'correct horse battery']);
        $this->post('/operator/code', ['code' => $totp->code(self::SECRET, $totp->step(time()))])->assertRedirect(route('operator.universes'));
    }
}
