<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Mail\BackupFailed;
use App\Services\Operators\OffsiteBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Backs up every database to the other server (config/offsite.php); a
 * failure is mailed to the operators (XGP_ERRORS_MAIL) at once.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class RunBackup extends Command
{
    /** @var string */
    protected $signature = 'xgp:backup:run';

    /** @var string */
    protected $description = 'Back up the base database and every universe to the other server (restic)';

    public function handle(OffsiteBackup $backup): int
    {
        try {
            $databases = $backup->run(time());
        } catch (RuntimeException $e) {
            $this->error('The backup failed: ' . $e->getMessage());
            $this->mail($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backed up: ' . implode(', ', $databases));

        return self::SUCCESS;
    }

    private function mail(string $message): void
    {
        $configured = config('operators.errors_mail');
        $to = array_values(array_filter(array_map('trim', explode(',', is_string($configured) ? $configured : '')), static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false));

        if ($to === []) {
            return;
        }

        try {
            Mail::to($to)->send(new BackupFailed($message));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
