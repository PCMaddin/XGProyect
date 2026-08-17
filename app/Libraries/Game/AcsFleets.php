<?php

declare(strict_types=1);

namespace App\Libraries\Game;

use Xgp\App\Core\Entity\AcsFleetEntity;

/**
 * Wraps a set of ACS group rows into typed entities.
 *
 * The legacy class stored a current-user id that nothing ever read; it has been
 * dropped. getFirstAcs() now returns an empty entity instead of indexing past
 * the end of an empty set.
 */
class AcsFleets
{
    /** @var list<AcsFleetEntity> */
    private array $acs = [];

    /**
     * @param  array<int, array<string, mixed>>  $acs
     */
    public function __construct(array $acs)
    {
        foreach ($acs as $fleet) {
            $this->acs[] = new AcsFleetEntity($fleet);
        }
    }

    /**
     * @return list<AcsFleetEntity>
     */
    public function getAcs(): array
    {
        return $this->acs;
    }

    public function getFirstAcs(): AcsFleetEntity
    {
        return $this->acs[0] ?? new AcsFleetEntity([]);
    }
}
