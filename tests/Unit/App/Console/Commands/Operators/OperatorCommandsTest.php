<?php

declare(strict_types=1);

namespace Tests\Unit\App\Console\Commands\Operators;

use App\Console\Commands\Operators\CreateOperator;
use App\Console\Commands\Operators\DeleteOperator;
use App\Console\Commands\Operators\ListOperators;
use App\Console\Commands\Operators\ResetOperator;
use App\Models\Operator;
use App\Services\Auth\Totp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Operator accounts are made on the server's command line, and two-factor
 * sign-in with them: an account exists only once the app showed the right
 * code. A lost phone gets a new app the same way.
 */
#[CoversClass(CreateOperator::class)]
#[CoversClass(ResetOperator::class)]
#[CoversClass(ListOperators::class)]
#[CoversClass(DeleteOperator::class)]
class OperatorCommandsTest extends DatabaseTestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    private const OTHER_SECRET = 'MFRGGZDFMZTWQ2LKMFRGGZDFMZTWQ2LK';

    private string $nextSecret = self::SECRET;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);
        // a known secret instead of a random one, to compute the app's code
        $test = $this;
        $this->app->instance(Totp::class, new class ($test) extends Totp {
            public function __construct(private OperatorCommandsTest $test)
            {
            }

            public function secret(): string
            {
                return $this->test->nextSecret();
            }
        });
    }

    public function nextSecret(): string
    {
        return $this->nextSecret;
    }

    public function testAnAccountIsMadeOnceTheAppShowsTheRightCode(): void
    {
        $this->command('xgp:operator:create', ['email' => ' Ops@Example.com '])
            ->expectsQuestion('Password (at least 12 characters)', 'a long enough password')
            ->expectsQuestion('Password again', 'a long enough password')
            ->expectsOutputToContain('type in the key GEZD GNBV')
            ->expectsQuestion('Code from the app', '000000')
            ->expectsOutputToContain('Wrong code.')
            ->expectsQuestion('Code from the app', self::code(self::SECRET))
            ->expectsOutputToContain('Two-factor sign-in is on')
            ->assertSuccessful();

        $operator = Operator::sole();
        $this->assertSame('ops@example.com', $operator->email);
        $this->assertTrue($operator->hasTwoFactor());
        $this->assertSame(self::SECRET, $operator->two_factor_secret);
        $this->assertTrue(Hash::check('a long enough password', $operator->password));
        $this->assertSame(1, DB::connection('central')->table('operator_events')->where('action', 'operator_created')->where('ip', 'cli')->count());

        $this->command('xgp:operator:create', ['email' => 'ops@example.com'])->expectsOutputToContain('already')->assertFailed();
    }

    public function testWithoutTheRightCodeThereIsNoAccount(): void
    {
        $this->command('xgp:operator:create', ['email' => 'ops@example.com', '--password' => 'a long enough password'])
            ->expectsQuestion('Code from the app', '111111')
            ->expectsQuestion('Code from the app', '222222')
            ->expectsQuestion('Code from the app', '333333')
            ->expectsOutputToContain('No account was created.')
            ->assertFailed();

        $this->assertSame(0, Operator::count());
    }

    public function testShortPasswordsAndBadAddressesAreRefused(): void
    {
        $this->command('xgp:operator:create', ['email' => 'not-an-address'])->expectsOutputToContain('Not an e-mail address')->assertFailed();
        $this->command('xgp:operator:create', ['email' => 'ops@example.com', '--password' => 'short'])->expectsOutputToContain('at least 12')->assertFailed();
        $this->command('xgp:operator:create', ['email' => 'ops@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'a long enough password')
            ->expectsQuestion('Password again', 'another long password')
            ->expectsOutputToContain('differ')
            ->assertFailed();

        $this->assertSame(0, Operator::count());
    }

    public function testALostPhoneGetsANewApp(): void
    {
        $this->createOperator();
        $this->nextSecret = self::OTHER_SECRET;

        $this->command('xgp:operator:reset', ['email' => 'ops@example.com'])->expectsOutputToContain('Say what to reset')->assertFailed();

        // a wrong code keeps the old app
        $this->command('xgp:operator:reset', ['email' => 'ops@example.com', '--two-factor' => true])
            ->expectsQuestion('Code from the app', '111111')
            ->expectsQuestion('Code from the app', '222222')
            ->expectsQuestion('Code from the app', '333333')
            ->assertFailed();
        $this->assertSame(self::SECRET, Operator::sole()->two_factor_secret);

        $this->command('xgp:operator:reset', ['email' => 'ops@example.com', '--two-factor' => true, '--password' => 'a brand new password'])
            ->expectsQuestion('Code from the app', self::code(self::OTHER_SECRET))
            ->assertSuccessful();

        $operator = Operator::sole();
        $this->assertSame(self::OTHER_SECRET, $operator->two_factor_secret);
        $this->assertTrue(Hash::check('a brand new password', $operator->password));
    }

    public function testOperatorsAreListedAndDeleted(): void
    {
        $this->createOperator();

        $this->command('xgp:operator:list')->expectsOutputToContain('ops@example.com')->assertSuccessful();
        $this->command('xgp:operator:delete', ['email' => 'nobody@example.com'])->assertFailed();
        $this->command('xgp:operator:delete', ['email' => 'ops@example.com'])->expectsConfirmation('Delete the operator ops@example.com?', 'no')->assertFailed();
        $this->assertSame(1, Operator::count());
        $this->command('xgp:operator:delete', ['email' => 'ops@example.com', '--force' => true])->assertSuccessful();
        $this->assertSame(0, Operator::count());
    }

    private function createOperator(): void
    {
        $this->command('xgp:operator:create', ['email' => 'ops@example.com', '--password' => 'a long enough password'])
            ->expectsQuestion('Code from the app', self::code(self::SECRET))
            ->assertSuccessful();
    }

    private static function code(string $secret): string
    {
        $totp = new Totp();

        return $totp->code($secret, $totp->step(time()));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
