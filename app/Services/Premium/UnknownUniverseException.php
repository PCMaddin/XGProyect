<?php

declare(strict_types=1);

namespace App\Services\Premium;

use RuntimeException;

/**
 * A webhook names a universe this server does not have.
 */
final class UnknownUniverseException extends RuntimeException
{
}
