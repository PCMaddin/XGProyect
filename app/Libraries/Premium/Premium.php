<?php

declare(strict_types=1);

namespace App\Libraries\Premium;

use Xgp\App\Core\Entity\PremiumEntity;

/**
 * Wraps a user's premium/officer rows into typed entities.
 *
 * The legacy class stored a current-user id that nothing ever read; it has been
 * dropped. getCurrentPremium() now returns an empty entity instead of indexing
 * past the end of an empty set.
 */
class Premium
{
    /** @var list<PremiumEntity> */
    private array $premium = [];

    /**
     * @param  array<int, array<string, mixed>>  $premium
     */
    public function __construct(array $premium)
    {
        foreach ($premium as $entry) {
            $this->premium[] = new PremiumEntity($entry);
        }
    }

    /**
     * @return list<PremiumEntity>
     */
    public function getPremium(): array
    {
        return $this->premium;
    }

    public function getCurrentPremium(): PremiumEntity
    {
        return $this->premium[0] ?? new PremiumEntity([]);
    }
}
