<?php

declare(strict_types=1);

namespace App\Services\Premium;

/**
 * A package of the dark matter shop (config/premium.php): what the player
 * gets and what it costs, in the smallest unit of the currency (cents).
 */
final class DarkMatterPackage
{
    public function __construct(
        public readonly string $id,
        public readonly int $darkMatter,
        public readonly int $price,
    ) {
    }
}
