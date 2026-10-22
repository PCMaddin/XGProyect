<?php

declare(strict_types=1);

namespace App\Libraries\Premium;

use App\Support\Concerns\HydratesModels;
use App\Models\Premium as PremiumModel;

/**
 * Wraps a user's premium/officer rows into typed entities.
 *
 * The legacy class stored a current-user id that nothing ever read; it has been
 * dropped. getCurrentPremium() now returns an empty entity instead of indexing
 * past the end of an empty set.
 */
class Premium
{
    use HydratesModels;

    /** @var list<PremiumModel> */
    private array $premium = [];

    /**
     * @param  array<int, array<string, mixed>>  $premium
     */
    public function __construct(array $premium)
    {
        foreach ($premium as $entry) {
            $this->premium[] = self::hydrateRow(PremiumModel::class, $entry);
        }
    }

    /**
     * @return list<PremiumModel>
     */
    public function getPremium(): array
    {
        return $this->premium;
    }

    public function getCurrentPremium(): PremiumModel
    {
        return $this->premium[0] ?? self::hydrateRow(PremiumModel::class, []);
    }
}
