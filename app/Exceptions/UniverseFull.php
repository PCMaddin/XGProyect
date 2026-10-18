<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * No place is left for a new home planet: the universe takes no more
 * players.
 */
class UniverseFull extends RuntimeException
{
}
