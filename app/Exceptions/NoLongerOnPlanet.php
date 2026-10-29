<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * What an action takes from a planet was gone when it was taken: another
 * request at the same moment took it first. The action's transaction is
 * rolled back; nothing is taken twice.
 */
class NoLongerOnPlanet extends RuntimeException
{
}
