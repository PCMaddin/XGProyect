<?php

declare(strict_types=1);

namespace App\Libraries\Game;

use App\Support\Concerns\HydratesModels;
use App\Models\Acs;

/**
 * Wraps a set of ACS group rows into typed entities.
 *
 * The legacy class stored a current-user id that nothing ever read; it has been
 * dropped. getFirstAcs() now returns an empty entity instead of indexing past
 * the end of an empty set.
 */
class AcsFleets
{
    use HydratesModels;

    /** @var list<Acs> */
    private array $acs = [];

    /**
     * @param  array<int, array<string, mixed>>  $acs
     */
    public function __construct(array $acs)
    {
        foreach ($acs as $fleet) {
            $this->acs[] = self::hydrateRow(Acs::class, $fleet);
        }
    }

    /**
     * @return list<Acs>
     */
    public function getAcs(): array
    {
        return $this->acs;
    }

    public function getFirstAcs(): Acs
    {
        return $this->acs[0] ?? self::hydrateRow(Acs::class, []);
    }
}
