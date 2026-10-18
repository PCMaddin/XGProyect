<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Operator;

use App\Http\Controllers\Operator\AccountController;
use App\Http\Controllers\Operator\LoginController;
use App\Http\Middleware\AuthenticateOperator;
use App\Http\Middleware\OperatorArea;
use App\Models\Operator;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use App\Services\Operators\OperatorLog;
use App\Services\Operators\OperatorLogin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * /operator lets in only an operator of the base database with password
 * and code, always: without two-factor sign-in (set up on the command line)
 * there is no way in. A code counts once, a few wrong ones end the step,
 * the session ends after a few hours, and everything is logged.
 */
#[CoversClass(LoginController::class)]
#[CoversClass(AccountController::class)]
#[CoversClass(OperatorLogin::class)]
#[CoversClass(OperatorArea::class)]
#[CoversClass(AuthenticateOperator::class)]
#[CoversClass(OperatorLog::class)]
class OperatorLoginTest extends DatabaseTestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    private const EMAIL = 'ops@example.com';
    private const PASSWORD = 'correct horse battery';

    /** @var list<string> */
    private array $recoveryCodes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        config(['operators.web' => true, 'operators.allowed_ips' => '']);

        $operator = new Operator(['email' => self::EMAIL, 'password' => self::PASSWORD, 'created_at' => time()]);
        $this->recoveryCodes = app(TwoFactor::class)->enable($operator, self::SECRET, $this->code(-60), time() - 60) ?? [];
    }

    public function testThePasswordAndThenTheCodeSignIn(): void
    {
        $this->get('/operator')->assertOk()->assertSee('name="password"', false)
            ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Cache-Control', 'no-store, private');

        $this->post('/operator/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertRedirect(route('operator.code'));
        // not signed in before the code
        $this->get('/operator/universes')->assertRedirect(route('operator.login'));

        $this->post('/operator/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->post('/operator/code', ['code' => '000000'])->assertRedirect(route('operator.code'))->assertSessionHasErrors('code');
        $this->post('/operator/code', ['code' => $this->code()])->assertRedirect(route('operator.universes'));

        $this->get('/operator/universes')->assertOk()->assertSee(self::EMAIL)->assertSee('Sign out');
        $operator = Operator::sole();
        $this->assertNotNull($operator->last_login_at);
        $this->assertSame(['login_failed' => 0, 'code_failed' => 1, 'login' => 1], $this->events(['login_failed', 'code_failed', 'login']));
    }

    public function testAWrongPasswordAndAnUnknownAccountGetTheSameAnswer(): void
    {
        $wrong = $this->post('/operator/login', ['email' => self::EMAIL, 'password' => 'wrong password']);
        $unknown = $this->post('/operator/login', ['email' => 'nobody@example.com', 'password' => self::PASSWORD]);

        foreach ([$wrong, $unknown] as $response) {
            $response->assertRedirect(route('operator.login'))->assertSessionHasErrors(['email' => __('operator/operator.op_login_failed')]);
        }

        $this->get('/operator/code')->assertRedirect(route('operator.login'));
        $this->assertSame(['login_failed' => 2], $this->events(['login_failed']));
    }

    public function testWithoutTwoFactorSignInThereIsNoWayIn(): void
    {
        Operator::create(['email' => 'new@example.com', 'password' => self::PASSWORD, 'created_at' => time()]);

        $this->post('/operator/login', ['email' => 'new@example.com', 'password' => self::PASSWORD])
            ->assertRedirect(route('operator.login'))->assertSessionHasErrors('email');
        $this->get('/operator/code')->assertRedirect(route('operator.login'));
    }

    public function testACodeCountsOnceAndARecoveryCodeToo(): void
    {
        $this->signIn($this->code());
        $this->post('/operator/logout')->assertRedirect(route('operator.login'));
        $this->get('/operator/universes')->assertRedirect(route('operator.login'));

        // the same code again: refused
        $this->post('/operator/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->post('/operator/code', ['code' => $this->code()])->assertSessionHasErrors('code');

        $this->post('/operator/code', ['code' => strtoupper($this->recoveryCodes[0])])->assertRedirect(route('operator.universes'));
        $this->post('/operator/logout');

        $this->post('/operator/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->post('/operator/code', ['code' => $this->recoveryCodes[0]])->assertSessionHasErrors('code');
    }

    public function testTooManyWrongCodesEndTheStep(): void
    {
        config(['operators.attempts' => 3]);
        $this->post('/operator/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->post('/operator/code', ['code' => '111111'])->assertRedirect(route('operator.code'));
        $this->post('/operator/code', ['code' => '222222'])->assertRedirect(route('operator.code'));
        $this->post('/operator/code', ['code' => '333333'])->assertRedirect(route('operator.login'));
        // even the right code is too late now
        $this->post('/operator/code', ['code' => $this->code()])->assertRedirect(route('operator.login'));
        $this->get('/operator/universes')->assertRedirect(route('operator.login'));
    }

    public function testTheSessionEndsAfterAFewHours(): void
    {
        config(['operators.max_hours' => 8]);
        $this->signIn($this->code());

        $this->travel(7)->hours();
        $this->get('/operator/universes')->assertOk();
        $this->travel(2)->hours();
        $this->get('/operator/universes')->assertRedirect(route('operator.login'));
    }

    public function testTheAreaIsOnlyThereWhereAllowed(): void
    {
        config(['operators.allowed_ips' => '203.0.113.0/24, 10.1.2.3']);
        $this->get('/operator')->assertNotFound();
        $this->post('/operator/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertNotFound();
        // a forged header does not count: no proxy is trusted
        $this->get('/operator', ['X-Forwarded-For' => '203.0.113.9'])->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/operator')->assertOk();

        config(['operators.allowed_ips' => '', 'operators.web' => false]);
        $this->get('/operator')->assertNotFound();
    }

    public function testTheSessionIsItsOwn(): void
    {
        $response = $this->get('/operator');

        $this->assertNotNull($response->getCookie('xgp_operator', false));
        $this->assertSame('strict', config('session.same_site'));
        $this->assertSame('file', config('session.driver'));
    }

    public function testThePasswordChangesOnlyWithTheCurrentOneAndACode(): void
    {
        $this->signIn($this->code(-30));
        $this->get('/operator/account')->assertOk()->assertSee('Recovery codes left: 8');

        $this->post('/operator/account/password', ['current_password' => 'wrong', 'code' => $this->code(), 'password' => 'a new long password', 'password_confirmation' => 'a new long password'])
            ->assertSessionHasErrors('current_password');
        $this->post('/operator/account/password', ['current_password' => self::PASSWORD, 'code' => $this->code(), 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->post('/operator/account/password', ['current_password' => self::PASSWORD, 'code' => $this->code(30), 'password' => 'a new long password', 'password_confirmation' => 'a new long password'])
            ->assertRedirect(route('operator.account'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('a new long password', Operator::sole()->password));
        $this->assertSame(['password_changed' => 1], $this->events(['password_changed']));
    }

    public function testNewRecoveryCodesAreShownOnce(): void
    {
        $this->signIn($this->code(-30));

        $this->post('/operator/account/codes', ['current_password' => self::PASSWORD, 'code' => $this->code()])->assertRedirect(route('operator.account'));
        $page = $this->get('/operator/account')->assertOk()->assertSee('id="new-codes"', false);
        $this->assertSame(8, app(TwoFactor::class)->recoveryCodesLeft(Operator::sole()));
        $this->assertStringNotContainsString($this->recoveryCodes[1], (string) $page->getContent());

        $this->get('/operator/account')->assertDontSee('id="new-codes"', false);
    }

    public function testTheOperatorChoosesTheLanguage(): void
    {
        $this->get('/operator?lang=de')->assertOk()->assertSee('Anmeldung für Betreiber');
        $this->get('/operator')->assertSee('Anmeldung für Betreiber');
        $this->get('/operator?lang=xx')->assertSee('Anmeldung für Betreiber');
        $this->get('/operator?lang=en')->assertSee('Operators’ sign-in', false);
    }

    private function signIn(string $code): void
    {
        $this->post('/operator/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertRedirect(route('operator.code'));
        $this->post('/operator/code', ['code' => $code])->assertRedirect(route('operator.universes'));
    }

    /**
     * The app's code now, or some seconds off.
     */
    private function code(int $offset = 0): string
    {
        $totp = new Totp();

        return $totp->code(self::SECRET, $totp->step(time() + $offset));
    }

    /**
     * @param list<string> $actions
     *
     * @return array<string, int>
     */
    private function events(array $actions): array
    {
        $counts = [];

        foreach ($actions as $action) {
            $counts[$action] = DB::connection('central')->table('operator_events')->where('action', $action)->count();
        }

        return $counts;
    }
}
