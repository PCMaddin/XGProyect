<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Models\Operator;
use App\Services\Auth\TwoFactor;
use App\Services\Operators\OperatorLog;
use Illuminate\Console\Command;

/**
 * A new password, or two-factor sign-in with a new app (a lost phone).
 */
class ResetOperator extends Command
{
    use EnrollsTwoFactor;

    /** @var string */
    protected $signature = 'xgp:operator:reset
        {email}
        {--new-password : set a new password}
        {--two-factor : set up two-factor sign-in with a new app; the old one stops working}
        {--password= : the new password instead of asking (avoid: it stays in the shell history)}';

    /** @var string */
    protected $description = 'Give an operator a new password or a new authenticator app';

    public function handle(TwoFactor $twoFactor, OperatorLog $log): int
    {
        $operator = Operator::where('email', mb_strtolower(trim($this->argument('email'))))->first();

        if ($operator === null) {
            $this->error('No operator ' . $this->argument('email') . '.');

            return self::FAILURE;
        }

        $newPassword = (bool) $this->option('new-password') || is_string($this->option('password'));

        if (!$newPassword && !$this->option('two-factor')) {
            $this->error('Say what to reset: --new-password and/or --two-factor.');

            return self::FAILURE;
        }

        if ($newPassword) {
            $password = $this->askPassword();

            if ($password === null) {
                return self::FAILURE;
            }

            $operator->forceFill(['password' => $password])->save();
            $this->info('New password set.');
        }

        // the old app keeps working until the new one shows the right code
        if ($this->option('two-factor') && !$this->enrollTwoFactor($twoFactor, $operator)) {
            $this->error('Two-factor sign-in is unchanged.');

            return self::FAILURE;
        }

        $log->record($operator, $operator->email, OperatorLog::ACCOUNT_RESET, 'cli', null, ['password' => $newPassword, 'two_factor' => (bool) $this->option('two-factor')]);

        return self::SUCCESS;
    }
}
