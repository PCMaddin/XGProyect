<?php

declare(strict_types=1);

namespace App\Libraries\Research;

use App\Support\Concerns\HydratesModels;
use App\Models\Research;

/**
 * Wraps a user's research rows into typed entities.
 *
 * The legacy class stored a current-user id that nothing ever read; it has been
 * dropped. getCurrentResearch() now returns an empty entity instead of indexing
 * past the end of an empty set.
 */
class Researches
{
    use HydratesModels;

    /** @var list<Research> */
    private array $research = [];

    /**
     * @param  array<int, array<string, mixed>>  $research
     */
    public function __construct(array $research)
    {
        foreach ($research as $entry) {
            $this->research[] = self::hydrateRow(Research::class, $entry);
        }
    }

    /**
     * @return list<Research>
     */
    public function getResearch(): array
    {
        return $this->research;
    }

    public function getCurrentResearch(): Research
    {
        return $this->research[0] ?? self::hydrateRow(Research::class, []);
    }
}
