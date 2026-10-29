<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Models\Operator;
use Illuminate\Console\Command;

class ListOperators extends Command
{
    /** @var string */
    protected $signature = 'xgp:operator:list';

    /** @var string */
    protected $description = 'List the operator accounts of /operator';

    public function handle(): int
    {
        $this->table(['E-mail', 'Two-factor', 'Last sign-in', 'From'], Operator::orderBy('email')->get()->map(static fn (Operator $operator): array => [
            $operator->email,
            $operator->hasTwoFactor() ? 'on' : 'OFF (cannot sign in)',
            $operator->last_login_at === null ? '-' : date('Y-m-d H:i', $operator->last_login_at),
            $operator->last_login_ip ?? '-',
        ])->all());

        return self::SUCCESS;
    }
}
