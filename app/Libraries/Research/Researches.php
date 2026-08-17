<?php

declare(strict_types=1);

namespace App\Libraries\Research;

use Xgp\App\Core\Entity\ResearchEntity;

/**
 * Wraps a user's research rows into typed entities.
 *
 * The legacy class stored a current-user id that nothing ever read; it has been
 * dropped. getCurrentResearch() now returns an empty entity instead of indexing
 * past the end of an empty set.
 */
class Researches
{
    /** @var list<ResearchEntity> */
    private array $research = [];

    /**
     * @param  array<int, array<string, mixed>>  $research
     */
    public function __construct(array $research)
    {
        foreach ($research as $entry) {
            $this->research[] = new ResearchEntity($entry);
        }
    }

    /**
     * @return list<ResearchEntity>
     */
    public function getResearch(): array
    {
        return $this->research;
    }

    public function getCurrentResearch(): ResearchEntity
    {
        return $this->research[0] ?? new ResearchEntity([]);
    }
}
