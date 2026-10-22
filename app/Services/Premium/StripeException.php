<?php

declare(strict_types=1);

namespace App\Services\Premium;

use RuntimeException;

/**
 * Stripe could not be reached or refused the request.
 */
final class StripeException extends RuntimeException
{
}
