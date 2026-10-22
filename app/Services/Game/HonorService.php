<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Core\GameObjects\GameObjectRegistry;
use Illuminate\Support\Facades\DB;

/**
 * The honour system of the original (owiki "Ehrenpunkte", OGame wiki
 * "Honor Points"):
 *
 * - a fight is neutral against inactive players, alliance members and
 *   buddies; honourable when the target has at least half of the
 *   attacker's military points (or at most 10 fewer), or is a bandit;
 *   dishonourable otherwise;
 * - honour points are floor(units ^ 0,9 / 1.000) of what was destroyed: in
 *   an honourable fight the attacker wins them for the defender's military
 *   units destroyed, in a dishonourable one he loses them (civil ships
 *   half, plus half of the loot); the defender wins them for the
 *   attacker's military units he destroyed and never loses any;
 * - loot: 50 %, 75 % in honourable fights, 100 % from bandits;
 * - titles by rank: Starlord (top 250, 500+), Imperator (top 100, 2.500+),
 *   Groß-Imperator (top 10, 15.000+); Bandit (lowest 250, -500 or less),
 *   Banditenlord (lowest 100, -2.500), Banditenkönig (lowest 10, -15.000).
 */
class HonorService
{
    public const NEUTRAL = 'neutral';
    public const HONOURABLE = 'honourable';
    public const DISHONOURABLE = 'dishonourable';

    private const CIVIL = [
        Ships::SHIP_SMALL_CARGO_SHIP, Ships::SHIP_BIG_CARGO_SHIP, Ships::SHIP_COLONY_SHIP, Ships::SHIP_RECYCLER,
        Ships::SHIP_ESPIONAGE_PROBE, Ships::SHIP_SOLAR_SATELLITE, Ships::SHIP_CRAWLER,
    ];

    /** title => [minimum points, top rank]; negative: lowest ranks */
    private const TITLES = [
        'grand_emperor' => [15000, 10],
        'emperor' => [2500, 100],
        'starlord' => [500, 250],
        'bandit_king' => [-15000, 10],
        'bandit_lord' => [-2500, 100],
        'bandit' => [-500, 250],
    ];

    /** @var array<int, array{military: float, honor: float, ally: int, online: int}> */
    private array $players = [];

    public function __construct(private GameObjectRegistry $registry)
    {
    }

    public static function points(float $units): int
    {
        return $units <= 0 ? 0 : (int) floor($units ** 0.9 / 1000);
    }

    /**
     * How a fight of the attackers against the defender counts.
     *
     * @param list<int> $attackerIds
     */
    public function classify(array $attackerIds, int $defenderId, int $now): string
    {
        $defender = $this->player($defenderId);

        if ($defender['online'] < $now - 7 * 86400 || $this->allFriendly($attackerIds, $defenderId)) {
            return self::NEUTRAL;
        }

        if ($this->isBandit($defenderId)) {
            return self::HONOURABLE;
        }

        $attackers = array_sum(array_map(fn (int $id): float => $this->player($id)['military'], array_unique($attackerIds)));

        return $defender['military'] >= $attackers / 2 || $defender['military'] >= $attackers - 10
            ? self::HONOURABLE
            : self::DISHONOURABLE;
    }

    /**
     * The share of the resources the attackers can take.
     */
    public function lootShare(string $fight, int $defenderId): float
    {
        if ($fight === self::NEUTRAL) {
            return 0.5;
        }

        if ($this->isBandit($defenderId)) {
            return 1.0;
        }

        return $fight === self::HONOURABLE ? 0.75 : 0.5;
    }

    /**
     * The units of ships and defenses; civil ships count $civil of their
     * value.
     *
     * @param array<int, int|float> $units
     */
    public function value(array $units, float $civil = 0.0): float
    {
        $value = 0.0;

        foreach ($units as $id => $count) {
            if (!$this->registry->has($id)) {
                continue;
            }

            $price = $this->registry->get($id)->getPrice();
            $value += ($price->getMetal() + $price->getCrystal() + $price->getDeuterium()) * $count * (in_array($id, self::CIVIL, true) ? $civil : 1.0);
        }

        return $value;
    }

    /**
     * Give or take honour points after a battle.
     *
     * @param array<int, float>     $attackerShares attacker id => share of the attack (sums to 1)
     * @param array<int, int|float> $defenderLost   the defender's destroyed ships and defenses
     * @param array<int, int|float> $attackersLost  the attackers' destroyed ships
     */
    public function award(string $fight, array $attackerShares, int $defenderId, array $defenderLost, array $attackersLost, float $loot): void
    {
        if ($fight === self::NEUTRAL) {
            return;
        }

        $this->add($defenderId, self::points($this->value($attackersLost)));

        $attackerPoints = $fight === self::HONOURABLE
            ? self::points($this->value($defenderLost))
            : -self::points($this->value($defenderLost, 0.5) + $loot / 2);

        foreach ($attackerShares as $attackerId => $share) {
            $this->add($attackerId, (int) round($attackerPoints * $share));
        }
    }

    public function isBandit(int $userId): bool
    {
        return in_array($this->titleOf($userId), ['bandit', 'bandit_lord', 'bandit_king'], true);
    }

    /**
     * The honour title of the player, or ''.
     */
    public function titleOf(int $userId): string
    {
        $points = $this->player($userId)['honor'];

        foreach (self::TITLES as $title => [$minimum, $rank]) {
            $fits = $minimum > 0 ? $points >= $minimum && $this->rank($points, '>') < $rank : $points <= $minimum && $this->rank($points, '<') < $rank;

            if ($fits) {
                return $title;
            }
        }

        return '';
    }

    /**
     * How many players have more ('>') or fewer ('<') honour points.
     */
    private function rank(float $points, string $direction): int
    {
        return DB::table('users_statistics')->where('user_statistic_honor_points', $direction, $points)->count();
    }

    private function add(int $userId, int $points): void
    {
        if ($points === 0) {
            return;
        }

        DB::table('users_statistics')->where('user_statistic_user_id', $userId)->increment('user_statistic_honor_points', $points);
        unset($this->players[$userId]);
    }

    /**
     * @param list<int> $attackerIds
     */
    private function allFriendly(array $attackerIds, int $defenderId): bool
    {
        $defender = $this->player($defenderId);

        foreach (array_unique($attackerIds) as $attackerId) {
            $sameAlliance = $defender['ally'] !== 0 && $this->player($attackerId)['ally'] === $defender['ally'];

            if (!$sameAlliance && !$this->buddies($attackerId, $defenderId)) {
                return false;
            }
        }

        return $attackerIds !== [];
    }

    private function buddies(int $one, int $other): bool
    {
        return DB::table('buddys')
            ->where('buddy_status', 1)
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair->where('buddy_sender', $one)->where('buddy_receiver', $other))
                ->orWhere(fn ($pair) => $pair->where('buddy_sender', $other)->where('buddy_receiver', $one)))
            ->exists();
    }

    /**
     * @return array{military: float, honor: float, ally: int, online: int}
     */
    private function player(int $userId): array
    {
        if (!isset($this->players[$userId])) {
            $row = DB::table('users AS u')
                ->leftJoin('users_statistics AS s', 's.user_statistic_user_id', '=', 'u.id')
                ->where('u.id', $userId)
                ->first(['u.ally_id', 'u.onlinetime', 's.user_statistic_military_points', 's.user_statistic_honor_points']);

            $data = $row === null ? [] : (array) $row;
            $number = fn (string $key): float => is_numeric($data[$key] ?? null) ? (float) $data[$key] : 0.0;

            $this->players[$userId] = [
                'military' => $number('user_statistic_military_points'),
                'honor' => $number('user_statistic_honor_points'),
                'ally' => (int) $number('ally_id'),
                'online' => (int) $number('onlinetime'),
            ];
        }

        return $this->players[$userId];
    }
}
