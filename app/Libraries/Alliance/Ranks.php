<?php

declare(strict_types=1);

namespace App\Libraries\Alliance;

use InvalidArgumentException;
use JsonException;
use Xgp\App\Core\Enumerators\AllianceRanksEnumerator as AllianceRanks;
use Xgp\App\Core\Enumerators\SwitchIntEnumerator as SwitchInt;

/**
 * The ranks of an alliance (the JSON column `alliance_ranks`): per rank id a
 * name and the nine rights (AllianceRanksEnumerator => SwitchIntEnumerator).
 * Rank 0 is the founder, rank 1 the newcomer.
 *
 * Invalid rank ids are ignored. The legacy class clamped them instead: a
 * negative id deleted the founder rank, an id past the end wrote a rank
 * without name. Invalid JSON yields no ranks instead of die().
 *
 * @phpstan-type Rank array{rank: string, rights: array<int, int>}
 */
final class Ranks
{
    public const FOUNDER = 0;

    public const NEWCOMER = 1;

    private const RIGHTS_COUNT = 9;

    /** @var array<int, Rank> */
    private array $ranks = [];

    public function __construct(?string $allianceRanks)
    {
        if ($allianceRanks === null || $allianceRanks === '') {
            return;
        }

        try {
            $decoded = json_decode($allianceRanks, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        foreach (is_array($decoded) ? $decoded : [] as $rankId => $rank) {
            if (is_array($rank)) {
                $this->ranks[(int) $rankId] = self::normalize($rank);
            }
        }
    }

    /**
     * Add a rank without any right. Tags are stripped; an empty name adds
     * nothing (the legacy class ended the request with die()).
     *
     * Bugfix: the name was also SQL-escaped, although it is stored through a
     * bound parameter, so an apostrophe showed up as \' in the game.
     */
    public function addNew(string $name): void
    {
        $name = strip_tags($name);

        if ($name === '') {
            return;
        }

        $this->ranks[] = [
            'rank' => $name,
            'rights' => [
                AllianceRanks::DELETE => SwitchInt::OFF,
                AllianceRanks::KICK => SwitchInt::OFF,
                AllianceRanks::APPLICATIONS => SwitchInt::OFF,
                AllianceRanks::VIEW_MEMBER_LIST => SwitchInt::OFF,
                AllianceRanks::APPLICATION_MANAGEMENT => SwitchInt::OFF,
                AllianceRanks::ADMINISTRATION => SwitchInt::OFF,
                AllianceRanks::ONLINE_STATUS => SwitchInt::OFF,
                AllianceRanks::SEND_CIRCULAR => SwitchInt::OFF,
                AllianceRanks::RIGHT_HAND => SwitchInt::OFF,
            ],
        ];
    }

    /**
     * @param array<int, int> $rights all nine rights
     */
    public function editRankById(int $rankId, array $rights): void
    {
        if (count($rights) !== self::RIGHTS_COUNT) {
            throw new InvalidArgumentException('A rank needs exactly ' . self::RIGHTS_COUNT . ' rights');
        }

        if (isset($this->ranks[$rankId])) {
            $this->ranks[$rankId]['rights'] = $rights;
        }
    }

    public function editRankNameById(int $rankId, string $name): void
    {
        if (isset($this->ranks[$rankId])) {
            $this->ranks[$rankId]['rank'] = $name;
        }
    }

    /**
     * Remove a rank; the following ranks move up one id, so the members'
     * rank ids must follow (AllianceRankService::deleteRank()). The founder
     * and newcomer ranks cannot be removed.
     *
     * @return bool whether a rank was removed
     */
    public function deleteRankById(int $rankId): bool
    {
        if ($rankId <= self::NEWCOMER || !isset($this->ranks[$rankId])) {
            return false;
        }

        unset($this->ranks[$rankId]);
        $this->ranks = array_values($this->ranks);

        return true;
    }

    /**
     * @return array<int, Rank>
     */
    public function getAllRanksAsArray(): array
    {
        return $this->ranks;
    }

    public function getAllRanksAsJsonString(): string
    {
        return json_encode($this->ranks, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The rank, or the newcomer rank for an unknown id (null if neither exists).
     *
     * @return Rank|null
     */
    public function getRankById(int $rankId): ?array
    {
        return $this->ranks[$rankId] ?? $this->ranks[1] ?? null;
    }

    /**
     * @param array<mixed> $rank
     *
     * @return Rank
     */
    private static function normalize(array $rank): array
    {
        $rights = [];
        foreach (is_array($rank['rights'] ?? null) ? $rank['rights'] : [] as $right => $value) {
            $rights[(int) $right] = is_numeric($value) ? (int) $value : SwitchInt::OFF;
        }

        return [
            'rank' => is_scalar($rank['rank'] ?? null) ? (string) $rank['rank'] : '',
            'rights' => $rights,
        ];
    }
}
