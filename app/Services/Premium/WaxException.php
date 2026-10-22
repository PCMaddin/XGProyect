<?php

declare(strict_types=1);

namespace App\Services\Premium;

use RuntimeException;

/**
 * The WAX chain or the exchange rate could not be read.
 */
final class WaxException extends RuntimeException
{
}
