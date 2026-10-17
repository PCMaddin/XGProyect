<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Enums\AllianceClass;
use App\Services\Game\AllianceClassService;
use App\Services\Game\AllianceService;
use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Support\Facades\DB;

/**
 * Alliances among bots, through the alliance page's own operations: a bot
 * without an alliance applies to the nearest bot alliance with room (one of
 * its members lives in the same galaxy at most 50 systems away; three
 * members at most, applications included) or, if there is none but another
 * bot without an alliance lives that close, founds one and takes
 * applications. The founder accepts bots and declines
 * players, so bots and players never share an alliance, and chooses the
 * warrior class once the first choice is free.
 *
 * Also answers who a bot's allies are, for {@see BotCoordinator}.
 */
class BotAlliance
{
    use ReadsMixedValues;

    /** systems between two members' home planets */
    private const RANGE = 50;

    /** members, applications included */
    private const MAX_MEMBERS = 3;

    private const APPLICATION = 'Neighbours should stand together.';

    public function __construct(
        private AllianceService $alliances,
        private AllianceClassService $classes,
    ) {
    }

    /**
     * One step a turn: answer applications as founder, wait for an answer,
     * apply or found.
     */
    public function act(int $botId, int $now): void
    {
        $bot = $this->bot($botId);

        if ($bot === null) {
            return;
        }

        $allyId = self::asInt($bot['ally_id'] ?? 0);
        $request = self::asInt($bot['ally_request'] ?? 0);

        if ($allyId !== 0) {
            $this->lead($botId, $allyId, $now);

            return;
        }

        if ($request !== 0) {
            // the alliance is gone, closed or full: try another one
            if (!in_array($request, $this->joinable($bot), true)) {
                $this->alliances->withdraw($botId);
            }

            return;
        }

        $nearest = $this->joinable($bot)[0] ?? null;

        if ($nearest !== null) {
            $this->alliances->apply($botId, $nearest, self::APPLICATION, $now);

            return;
        }

        if ($this->hasLonelyNeighbour($bot)) {
            $this->found($bot, $now);
        }
    }

    /**
     * The other members of the bot's alliance.
     *
     * @param array<string, mixed> $user
     *
     * @return list<int>
     */
    public function allies(array $user): array
    {
        $allyId = self::asInt($user['ally_id'] ?? 0);

        if ($allyId === 0) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): int => self::asInt($id),
            DB::table('users')->where('ally_id', $allyId)->where('id', '<>', self::asInt($user['id'] ?? 0))->pluck('id')->all()
        ));
    }

    /**
     * The founder accepts bots while there is room and declines the rest.
     */
    private function lead(int $botId, int $allyId, int $now): void
    {
        $alliance = DB::table('alliance')->where('alliance_id', $allyId)->where('alliance_owner', $botId)->first();

        if ($alliance === null) {
            return;
        }

        $members = DB::table('users')->where('ally_id', $allyId)->count();

        foreach (DB::table('users')->where('ally_request', $allyId)->orderBy('ally_register_time')->get(['id', 'user_is_bot']) as $applicant) {
            $accept = (bool) $applicant->user_is_bot && $members < self::MAX_MEMBERS;

            if ($this->alliances->answer(self::asInt($applicant->id), $allyId, $accept) && $accept) {
                $members++;
            }
        }

        if ($this->classes->classOf($allyId) === AllianceClass::None && $this->classes->priceOf($allyId, $now) === 0) {
            $this->classes->choose($allyId, $botId, AllianceClass::Warrior, $now);
        }
    }

    /**
     * Open bot alliances with room and a member nearby, the nearest first.
     *
     * @param array<string, mixed> $bot
     *
     * @return list<int>
     */
    private function joinable(array $bot): array
    {
        $distances = [];

        foreach (DB::table('alliance')->where('alliance_request_notallow', 1)->pluck('alliance_id') as $id) {
            $allyId = self::asInt($id);
            $members = $this->homes(fn ($users) => $users->where('u.ally_id', $allyId)->orWhere('u.ally_request', $allyId));
            $nearest = PHP_INT_MAX;

            foreach ($members as $member) {
                if (!(bool) ($member['user_is_bot'] ?? false)) {
                    continue 2;
                }

                if (self::asInt($member['id'] ?? 0) !== self::asInt($bot['id'] ?? 0)) {
                    $nearest = min($nearest, $this->distance($bot, $member));
                }
            }

            if (count($members) - (self::asInt($bot['ally_request'] ?? 0) === $allyId ? 1 : 0) < self::MAX_MEMBERS && $nearest <= self::RANGE) {
                $distances[$allyId] = $nearest;
            }
        }

        asort($distances);

        return array_keys($distances);
    }

    /**
     * Another bot without an alliance or application lives nearby.
     *
     * @param array<string, mixed> $bot
     */
    private function hasLonelyNeighbour(array $bot): bool
    {
        $lonely = $this->homes(fn ($users) => $users->where('u.user_is_bot', true)->where('u.ally_id', 0)->where('u.ally_request', 0)->where('u.id', '<>', self::asInt($bot['id'] ?? 0)));

        foreach ($lonely as $other) {
            if ($this->distance($bot, $other) <= self::RANGE) {
                return true;
            }
        }

        return false;
    }

    /**
     * A tag and name after the founder's home system, open for applications.
     *
     * @param array<string, mixed> $bot
     */
    private function found(array $bot, int $now): void
    {
        $base = self::asInt($bot['planet_galaxy'] ?? 0) . '-' . self::asInt($bot['planet_system'] ?? 0);

        foreach (array_merge([''], range('a', 'z')) as $suffix) {
            $tag = 'B' . $base . $suffix;
            $name = 'Bots ' . $base . $suffix;

            if (!$this->alliances->tagTaken($tag) && !$this->alliances->nameTaken($name)) {
                $this->alliances->openForApplications($this->alliances->found(self::asInt($bot['id'] ?? 0), $name, $tag, $now));

                return;
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bot(int $botId): ?array
    {
        return $this->homes(fn ($users) => $users->where('u.id', $botId)->where('u.user_is_bot', true))[0] ?? null;
    }

    /**
     * Users with the coordinates of their home planet.
     *
     * @param callable(\Illuminate\Database\Query\Builder): mixed $filter
     *
     * @return list<array<string, mixed>>
     */
    private function homes(callable $filter): array
    {
        $query = DB::table('users AS u')
            ->join('planets AS p', 'p.planet_id', '=', 'u.home_planet_id')
            ->select('u.id', 'u.user_is_bot', 'u.ally_id', 'u.ally_request', 'p.planet_galaxy', 'p.planet_system');

        $query->where(fn ($users) => $filter($users));

        return array_values(array_map(static fn (object $row): array => get_object_vars($row), $query->get()->all()));
    }

    /**
     * @param array<string, mixed> $one
     * @param array<string, mixed> $other
     */
    private function distance(array $one, array $other): int
    {
        return self::asInt($one['planet_galaxy'] ?? 0) === self::asInt($other['planet_galaxy'] ?? 0)
            ? abs(self::asInt($one['planet_system'] ?? 0) - self::asInt($other['planet_system'] ?? 0))
            : PHP_INT_MAX;
    }
}
