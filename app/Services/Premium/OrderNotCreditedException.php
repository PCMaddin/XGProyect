<?php

declare(strict_types=1);

namespace App\Services\Premium;

use RuntimeException;

/**
 * A paid order could not be credited (the player's account is gone): the
 * transaction is rolled back and the order stays pending for the admin.
 */
final class OrderNotCreditedException extends RuntimeException
{
}
