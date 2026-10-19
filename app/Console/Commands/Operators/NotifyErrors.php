<?php

declare(strict_types=1);

namespace App\Console\Commands\Operators;

use App\Mail\ErrorDigest;
use App\Services\Operators\ErrorMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mails the operators the kinds of error that piled up since the last mail
 * (config/operators.php), and deletes resolved ones gone for 90 days.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class NotifyErrors extends Command
{
    /** @var string */
    protected $signature = 'xgp:errors:notify';

    /** @var string */
    protected $description = 'Mail the operators the errors that piled up in all universes';

    public function handle(ErrorMonitor $monitor): int
    {
        $now = time();
        $monitor->prune($now);

        $configured = config('operators.errors_mail');
        $to = array_values(array_filter(array_map('trim', explode(',', is_string($configured) ? $configured : '')), static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false));

        if ($to === []) {
            return self::SUCCESS;
        }

        $threshold = config('operators.errors_threshold');
        $due = $monitor->takeDue(is_int($threshold) ? $threshold : 5, $now);

        if ($due === []) {
            return self::SUCCESS;
        }

        try {
            Mail::to($to)->send(new ErrorDigest($due));
        } catch (Throwable $e) {
            $this->error('The mail could not be sent: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(count($due) . ' kinds of error mailed.');

        return self::SUCCESS;
    }
}
