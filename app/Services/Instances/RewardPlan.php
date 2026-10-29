<?php

declare(strict_types=1);

namespace App\Services\Instances;

use InvalidArgumentException;

/**
 * What the best of a season get: dark matter per ranking, shared out over
 * the places in percent (staggered: the first gets most).
 */
final class RewardPlan
{
    /**
     * @param array<string, int> $pools  dark matter per ranking
     * @param list<int>          $shares percent for places 1, 2, 3 …
     */
    public function __construct(
        public readonly array $pools,
        public readonly array $shares,
    ) {
        foreach ($pools as $category => $amount) {
            if (!array_key_exists($category, SeasonResults::CATEGORIES) || $amount < 0) {
                throw new InvalidArgumentException('Rewards are given for ' . implode(', ', array_keys(SeasonResults::CATEGORIES)) . ', in dark matter.');
            }
        }

        if (count($shares) > SeasonResults::TOP || array_sum($shares) > 100 || min($shares ?: [0]) < 0) {
            throw new InvalidArgumentException('The shares are percent for up to ' . SeasonResults::TOP . ' places, together at most 100.');
        }
    }

    /**
     * From "total=100000,military=20000" and "30,20,15".
     */
    public static function parse(string $rewards, string $shares): self
    {
        $pools = [];

        foreach (array_filter(array_map('trim', explode(',', $rewards))) as $pair) {
            [$category, $amount] = array_pad(explode('=', $pair, 2), 2, '');

            if (!ctype_digit(trim($amount))) {
                throw new InvalidArgumentException('Not a reward: ' . $pair);
            }

            $pools[trim($category)] = (int) trim($amount);
        }

        $percent = [];

        foreach (array_filter(array_map('trim', explode(',', $shares)), static fn (string $share): bool => $share !== '') as $share) {
            if (!ctype_digit($share)) {
                throw new InvalidArgumentException('Not a share: ' . $share);
            }

            $percent[] = (int) $share;
        }

        return new self($pools, $percent);
    }

    /**
     * The plan of config/seasons.php.
     */
    public static function configured(): self
    {
        $rewards = config('seasons.rewards');
        $shares = config('seasons.shares');

        return self::parse(is_string($rewards) ? $rewards : '', is_string($shares) ? $shares : '');
    }

    public static function fromJson(?string $json): ?self
    {
        $data = json_decode((string) $json, true);

        if (!is_array($data) || !is_array($data['pools'] ?? null) || !is_array($data['shares'] ?? null)) {
            return null;
        }

        return new self(
            array_map('intval', array_filter($data['pools'], 'is_numeric')),
            array_values(array_map('intval', array_filter($data['shares'], 'is_numeric'))),
        );
    }

    public function toJson(): string
    {
        return (string) json_encode(['pools' => $this->pools, 'shares' => $this->shares]);
    }

    /**
     * The dark matter of a place (1 = first) in a ranking.
     */
    public function reward(string $category, int $rank): int
    {
        $share = $this->shares[$rank - 1] ?? 0;

        return intdiv(($this->pools[$category] ?? 0) * $share, 100);
    }

    public function isEmpty(): bool
    {
        return array_sum($this->pools) === 0 || array_sum($this->shares) === 0;
    }
}
