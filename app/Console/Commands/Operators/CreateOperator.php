<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Models\Operator;
use App\Services\Auth\TwoFactor;
use App\Services\Instances\InstanceProvisioner;
use App\Services\Operators\OperatorLog;
use Illuminate\Console\Command;

/**
 * An account for /operator, with two-factor sign-in set up right here.
 */
class CreateOperator extends Command
{
    use EnrollsTwoFactor;

    /** @var string */
    protected $signature = 'xgp:operator:create
        {email}
        {--password= : instead of asking (avoid: it stays in the shell history)}';

    /** @var string */
    protected $description = 'Create an operator account for /operator, with two-factor sign-in';

    public function handle(InstanceProvisioner $provisioner, TwoFactor $twoFactor, OperatorLog $log): int
    {
        $email = mb_strtolower(trim($this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Not an e-mail address: ' . $email);

            return self::FAILURE;
        }

        $provisioner->prepareRegistry();

        if (Operator::where('email', $email)->exists()) {
            $this->error('There is an operator ' . $email . ' already.');

            return self::FAILURE;
        }

        $password = $this->askPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $operator = new Operator(['email' => $email, 'password' => $password, 'created_at' => time()]);

        // saved only once the app shows the right code
        if (!$this->enrollTwoFactor($twoFactor, $operator)) {
            $this->error('No account was created.');

            return self::FAILURE;
        }

        $log->record($operator, $email, OperatorLog::ACCOUNT_CREATED, 'cli');
        $this->info('Operator ' . $email . ' can sign in on ' . url('operator') . '.');

        return self::SUCCESS;
    }
}
