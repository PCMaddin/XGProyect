<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Models\Operator;
use App\Services\Operators\OperatorLog;
use Illuminate\Console\Command;

class DeleteOperator extends Command
{
    /** @var string */
    protected $signature = 'xgp:operator:delete {email} {--force : without asking}';

    /** @var string */
    protected $description = 'Delete an operator account of /operator';

    public function handle(OperatorLog $log): int
    {
        $operator = Operator::where('email', mb_strtolower(trim($this->argument('email'))))->first();

        if ($operator === null) {
            $this->error('No operator ' . $this->argument('email') . '.');

            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm('Delete the operator ' . $operator->email . '?')) {
            return self::FAILURE;
        }

        $log->record($operator, $operator->email, OperatorLog::ACCOUNT_DELETED, 'cli');
        $operator->delete();
        $this->info('Deleted ' . $operator->email . '.');

        return self::SUCCESS;
    }
}
