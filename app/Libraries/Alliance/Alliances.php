<?php

declare(strict_types=1);

namespace App\Libraries\Alliance;

use App\Support\Concerns\HydratesModels;
use App\Models\Alliance;
use App\Core\Enumerators\SwitchIntEnumerator;

/**
 * Wraps a user's alliance rows into typed entities and answers the
 * owner/rank permission questions for the alliance UI.
 *
 * getCurrentAlliance() returns an empty entity instead of indexing [0] on an
 * empty set.
 */
class Alliances
{
    use HydratesModels;

    /** @var list<Alliance> */
    private array $alliances = [];

    /**
     * @param  array<int, mixed>  $alliances
     */
    public function __construct(
        array $alliances,
        private int $currentUserId,
        private int $currentUserRankId = 0,
    ) {
        foreach (array_filter($alliances) as $alliance) {
            if (is_array($alliance)) {
                $this->alliances[] = self::hydrateRow(Alliance::class, $alliance);
            }
        }
    }

    /**
     * @return list<Alliance>
     */
    public function getAlliances(): array
    {
        return $this->alliances;
    }

    public function getCurrentAlliance(): Alliance
    {
        return $this->alliances[0] ?? self::hydrateRow(Alliance::class, []);
    }

    public function getCurrentAllianceRankObject(): Ranks
    {
        return new Ranks($this->getCurrentAlliance()->alliance_ranks);
    }

    public function isOwner(): bool
    {
        return (int) $this->getCurrentAlliance()->alliance_owner === $this->currentUserId;
    }

    public function checkRank(int $rank): bool
    {
        if ($rank === 0) {
            return false;
        }

        $ranks = $this->getCurrentAllianceRankObject();

        if ($ranks->getAllRanksAsArray() === []) {
            return false;
        }

        $rights = $ranks->getRankById($this->currentUserRankId)['rights'] ?? [];

        return ($rights[$rank] ?? null) == SwitchIntEnumerator::ON;
    }

    public function hasAccess(int $rank): bool
    {
        return $this->isOwner() || $this->checkRank($rank);
    }
}
