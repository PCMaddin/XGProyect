<?php

declare(strict_types=1);

namespace App\Console\Commands\Premium;

use App\Services\Instances\InstanceProvisioner;
use App\Services\Instances\InstanceRegistry;
use App\Services\Premium\WaxException;
use App\Services\Premium\WaxPayments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Credits the dark matter paid in WAX since the last run; the scheduler
 * runs it every minute while a WAX account is set.
 */
class CheckWaxPayments extends Command
{
    /** @var string */
    protected $signature = 'xgp:wax:check';

    /** @var string */
    protected $description = 'Credit dark matter paid by WAX transfer';

    public function handle(WaxPayments $payments, InstanceProvisioner $provisioner): int
    {
        if (!DB::connection(InstanceRegistry::CONNECTION)->getSchemaBuilder()->hasTable('wax_transfers')) {
            $provisioner->prepareRegistry();
        }

        try {
            $results = $payments->check(time());
        } catch (WaxException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($results === []) {
            $this->info('No new transfers.');
        }

        foreach ($results as $result => $count) {
            $this->line($result . ': ' . $count);
        }

        return self::SUCCESS;
    }
}
