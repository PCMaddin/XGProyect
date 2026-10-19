<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Operators;

use App\Console\Commands\Operators\NotifyErrors;
use App\Exceptions\Handler;
use App\Http\Controllers\Operator\ErrorsController;
use App\Mail\ErrorDigest;
use App\Models\Operator;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\Legal\LegalPages;
use App\Services\Operators\ErrorMonitor;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\OptionsTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\DatabaseTestCase;

/**
 * Every reported error lands in the base database, one group per kind
 * (class, place, message without its numbers); the operators see and
 * resolve them on /operator, and get a mail once a kind piles up. Recording
 * never throws, not even without the table.
 */
#[CoversClass(ErrorMonitor::class)]
#[CoversClass(ErrorsController::class)]
#[CoversClass(NotifyErrors::class)]
#[CoversClass(ErrorDigest::class)]
#[CoversClass(Handler::class)]
class ErrorMonitorTest extends DatabaseTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        config(['INSTALLED' => 'true', 'SECRETWORD' => 'test-secret', 'operators.web' => true, 'operators.allowed_ips' => '', 'app.url' => 'https://game.example.org']);
        $this->seed([OptionsTableSeeder::class, LanguagesTableSeeder::class]);
    }

    public function testTheSameKindOfErrorIsOneGroup(): void
    {
        $monitor = app(ErrorMonitor::class);
        $monitor->record(self::error('Order 17 of "alice" failed'), 100);
        $monitor->record(self::error('Order 42 of "bob" failed'), 200);
        $monitor->record(self::error('Planet missing'), 300);

        $this->assertSame(2, DB::connection('central')->table('error_groups')->count());
        $orders = DB::connection('central')->table('error_groups')->where('message', 'like', 'Order%')->sole();
        $planet = DB::connection('central')->table('error_groups')->where('message', 'Planet missing')->sole();
        $this->assertSame([2, 1], [(int) $orders->occurrences, (int) $planet->occurrences]);
        // the first message and trace stay, the last time counts
        $this->assertSame('Order 17 of "alice" failed', $orders->message);
        $this->assertSame([100, 200], [(int) $orders->first_seen, (int) $orders->last_seen]);
        $this->assertSame('tests/Unit/App/Services/Operators/ErrorMonitorTest.php', $orders->file);
        $this->assertStringContainsString('#0', $orders->trace);
    }

    public function testAResolvedErrorThatComesBackIsOpenAgain(): void
    {
        $monitor = app(ErrorMonitor::class);
        $monitor->record(self::error('Queue stuck'), 100);
        $id = (int) DB::connection('central')->table('error_groups')->sole()->id;
        $monitor->setStatus($id, ErrorMonitor::RESOLVED);

        $monitor->record(self::error('Queue stuck'), 200);

        $this->assertSame(ErrorMonitor::OPEN, DB::connection('central')->table('error_groups')->value('status'));
    }

    public function testRecordingNeverThrows(): void
    {
        Schema::connection('central')->drop('error_groups');
        app(ErrorMonitor::class)->record(self::error('anything'), 100);

        config(['database.connections.central.database' => '/nonexistent/dir/db.sqlite']);
        DB::purge('central');
        app(ErrorMonitor::class)->record(self::error('anything'), 100);

        $this->addToAssertionCount(1);
    }

    public function testAnErrorOnAPageIsRecordedWithThePathButNotTheQuery(): void
    {
        $pages = $this->createMock(LegalPages::class);
        $pages->method('text')->willThrowException(new RuntimeException('Exploded 7'));
        $this->app->instance(LegalPages::class, $pages);

        $this->get('/legal/imprint?lang=en&token=secret-token')->assertStatus(500);

        $group = DB::connection('central')->table('error_groups')->sole();
        $this->assertSame(RuntimeException::class, $group->class);
        $this->assertSame('GET /legal/imprint', $group->context);
    }

    public function testOperatorsSeeAndResolveErrors(): void
    {
        app(ErrorMonitor::class)->record(self::error('Fleet <b>lost</b> at 1:2:3'), time());
        $id = (int) DB::connection('central')->table('error_groups')->sole()->id;

        $this->signInAsOperator();
        $this->get('/operator/errors')->assertOk()->assertSee('Fleet &lt;b&gt;lost&lt;/b&gt; at 1:2:3', false)->assertSee('RuntimeException');
        $this->get('/operator/errors/' . $id)->assertOk()->assertSee('ErrorMonitorTest.php')->assertSee('#0');

        $this->from('/operator/errors/' . $id)->post('/operator/errors/' . $id, ['status' => 'resolved'])->assertRedirect('/operator/errors/' . $id);
        $this->get('/operator/errors')->assertOk()->assertDontSee('Fleet');
        $this->get('/operator/errors?status=resolved')->assertOk()->assertSee('Fleet');
        $this->get('/operator/errors/999')->assertNotFound();
    }

    public function testTheOperatorsGetAMailOnceAKindPilesUp(): void
    {
        Mail::fake();
        config(['operators.errors_mail' => 'ops@example.com, not-an-address', 'operators.errors_threshold' => 3]);
        $monitor = app(ErrorMonitor::class);

        $monitor->record(self::error('Tick failed'), time());
        $monitor->record(self::error('Tick failed'), time());
        $this->notify();
        Mail::assertNothingSent();

        $monitor->record(self::error('Tick failed'), time());
        $this->notify();
        Mail::assertSent(ErrorDigest::class, fn (ErrorDigest $mail): bool => $mail->hasTo('ops@example.com') && !$mail->hasTo('not-an-address') &&
            count($mail->groups) === 1 && data_get($mail->groups, '0.pending') == 3);

        // at most hourly per kind, and never for a muted one
        foreach (range(1, 5) as $ignored) {
            $monitor->record(self::error('Tick failed'), time());
        }
        $this->notify();
        Mail::assertSent(ErrorDigest::class, 1);

        $html = (new ErrorDigest(array_values(DB::connection('central')->table('error_groups')->get()->all())))->render();
        $this->assertStringContainsString('https://game.example.org/operator/errors/', $html);
    }

    /**
     * The mail travels unencrypted: it names the kind of error, never the
     * values in the message (a query's values, a token). /operator has them.
     */
    public function testTheMailNamesTheKindOfErrorButNotItsValues(): void
    {
        $monitor = app(ErrorMonitor::class);
        $monitor->record(self::error("Token 'wax-link-s3cret' of player 42 rejected"), time());

        $html = (new ErrorDigest(array_values(DB::connection('central')->table('error_groups')->get()->all())))->render();

        $this->assertStringNotContainsString('s3cret', $html);
        $this->assertStringContainsString('Token ? of player N rejected', $html);
        $stored = DB::connection('central')->table('error_groups')->value('message');
        $this->assertIsString($stored);
        $this->assertStringContainsString('wax-link-s3cret', $stored);
    }

    public function testResolvedErrorsGoneForThreeMonthsAreDeleted(): void
    {
        $monitor = app(ErrorMonitor::class);
        $monitor->record(self::error('Old'), time() - 100 * 86400);
        $monitor->record(self::error('Old but open'), time() - 100 * 86400);
        $monitor->setStatus((int) DB::connection('central')->table('error_groups')->where('message', 'Old')->sole()->id, ErrorMonitor::RESOLVED);

        $this->assertSame(1, $monitor->prune(time()));
        $this->assertSame(['Old but open'], DB::connection('central')->table('error_groups')->pluck('message')->all());
    }

    private function notify(): void
    {
        $command = $this->artisan('xgp:errors:notify');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();
    }

    /**
     * Always thrown here: one place, so only the message tells them apart.
     */
    private static function error(string $message): RuntimeException
    {
        return new RuntimeException($message);
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
