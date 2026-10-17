<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\Enumerators\MessagesEnumerator;
use App\Core\Enumerators\PlanetTypesEnumerator;
use App\Libraries\Functions;
use App\Services\SettingsService;
use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Support\Facades\DB;

/**
 * Relocating a planet (owiki "Umsiedeln"):
 *
 * - a planet moves to a free position of the same band (1-3, 4-12, 13-15)
 *   in any system; the position is reserved for 24 hours, then the planet
 *   and its moon move with buildings, defence and resources;
 * - it costs 240.000 dark matter (relocation_price), which must be there
 *   at the start but is only taken when the planet moves;
 * - neither at the start nor at the move may the planet or its moon
 *   build, research, have a shipyard order or own fleets flying from or to
 *   it; otherwise, or in vacation mode, the relocation is cancelled;
 * - the move itself (ships, holding fleets, jump gate) is PlanetMover's.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class RelocationService
{
    use ReadsMixedValues;

    public const DURATION = 86400;

    /** the bands of positions a planet stays in */
    private const BANDS = [[1, 3], [4, 12], [13, MAX_PLANET_IN_SYSTEM]];

    public function __construct(
        private PlanetMover $mover,
        private SettingsService $settings,
    ) {
    }

    public function price(): int
    {
        return max(0, $this->settings->getInt('relocation_price'));
    }

    public function sameBand(int $from, int $to): bool
    {
        foreach (self::BANDS as [$low, $high]) {
            if ($from >= $low && $from <= $high) {
                return $to >= $low && $to <= $high;
            }
        }

        return false;
    }

    /**
     * Whether a planet is at the position or a relocation reserved it.
     */
    public function taken(int $galaxy, int $system, int $position, int $exceptPlanetId = 0): bool
    {
        $planet = DB::table('planets')
            ->where('planet_galaxy', $galaxy)
            ->where('planet_system', $system)
            ->where('planet_planet', $position)
            ->exists();

        return $planet || DB::table('planets')
            ->where('planet_relocate_at', '>', 0)
            ->where('planet_relocate_galaxy', $galaxy)
            ->where('planet_relocate_system', $system)
            ->where('planet_relocate_planet', $position)
            ->where('planet_id', '<>', $exceptPlanetId)
            ->exists();
    }

    /**
     * Start relocating the planet. Error language key, or null.
     */
    public function start(int $userId, int $planetId, int $galaxy, int $system, int $position, int $now): ?string
    {
        return DB::transaction(function () use ($userId, $planetId, $galaxy, $system, $position, $now): ?string {
            $planet = $this->lockedPlanet($userId, $planetId);

            if ($planet === null) {
                return 'rl_error_planet';
            }

            if ((int) $planet->planet_relocate_at > 0) {
                return 'rl_error_running';
            }

            $error = $this->targetError($planet, $galaxy, $system, $position)
                ?? $this->hindrance($userId, $planet);

            if ($error !== null) {
                return $error;
            }

            DB::table('planets')->where('planet_id', $planetId)->update([
                'planet_relocate_galaxy' => $galaxy,
                'planet_relocate_system' => $system,
                'planet_relocate_planet' => $position,
                'planet_relocate_at' => $now + self::DURATION,
            ]);

            return null;
        });
    }

    public function cancel(int $userId, int $planetId): bool
    {
        return $this->clear(
            DB::table('planets')->where('planet_id', $planetId)->where('planet_user_id', $userId)->where('planet_relocate_at', '>', 0)
        ) === 1;
    }

    /**
     * The running relocation of the planet.
     *
     * @return array{galaxy: int, system: int, position: int, at: int}|null
     */
    public function running(int $planetId): ?array
    {
        $planet = DB::table('planets')->where('planet_id', $planetId)->where('planet_relocate_at', '>', 0)->first();

        if ($planet === null) {
            return null;
        }

        return [
            'galaxy' => (int) $planet->planet_relocate_galaxy,
            'system' => (int) $planet->planet_relocate_system,
            'position' => (int) $planet->planet_relocate_planet,
            'at' => (int) $planet->planet_relocate_at,
        ];
    }

    /**
     * Move the planets whose relocation is due.
     */
    public function settleDue(int $now): void
    {
        $due = DB::table('planets')
            ->where('planet_relocate_at', '>', 0)
            ->where('planet_relocate_at', '<=', $now)
            ->orderBy('planet_relocate_at')
            ->pluck('planet_id');

        foreach ($due as $planetId) {
            $this->settle(self::asInt($planetId), $now);
        }
    }

    /**
     * Move the planet when its relocation is due. Error language key when
     * it was cancelled instead, or null.
     */
    public function settle(int $planetId, int $now): ?string
    {
        return DB::transaction(function () use ($planetId, $now): ?string {
            $planet = DB::table('planets')
                ->where('planet_id', $planetId)
                ->where('planet_relocate_at', '>', 0)
                ->where('planet_relocate_at', '<=', $now)
                ->lockForUpdate()
                ->first();

            if ($planet === null) {
                return null;
            }

            $userId = (int) $planet->planet_user_id;
            $target = [(int) $planet->planet_relocate_galaxy, (int) $planet->planet_relocate_system, (int) $planet->planet_relocate_planet];
            $error = $this->settleError($userId, $planet, $target);

            if ($error !== null) {
                $this->clear(DB::table('planets')->where('planet_id', $planetId));
                $this->tell($userId, (int) $planet->planet_relocate_at, 'rl_failed', [$this->coords($target), (string) __('game/planetlayer.' . $error)]);

                return $error;
            }

            $this->mover->move($planet, $this->moonId($planet), $target, $now);
            $this->tell($userId, (int) $planet->planet_relocate_at, 'rl_done', [$this->coords($target)]);

            return null;
        });
    }

    /**
     * Why the planet cannot go to the target, or null.
     */
    private function targetError(\stdClass $planet, int $galaxy, int $system, int $position): ?string
    {
        if (min($galaxy, $system) < 1 || $galaxy > MAX_GALAXY_IN_WORLD || $system > MAX_SYSTEM_IN_GALAXY) {
            return 'rl_error_target';
        }

        if (!$this->sameBand((int) $planet->planet_planet, $position)) {
            return 'rl_error_band';
        }

        if ($this->taken($galaxy, $system, $position, (int) $planet->planet_id)) {
            return 'rl_error_taken';
        }

        return null;
    }

    /**
     * Why the planet cannot move now, or null.
     */
    private function hindrance(int $userId, \stdClass $planet): ?string
    {
        if (self::asInt(DB::table('preferences')->where('preference_user_id', $userId)->value('preference_vacation_mode')) > 0) {
            return 'rl_error_vacation';
        }

        if (self::asInt(DB::table('premium')->where('premium_user_id', $userId)->value('premium_dark_matter')) < $this->price()) {
            return 'rl_error_dark_matter';
        }

        $ids = array_filter([(int) $planet->planet_id, $this->moonId($planet)]);

        return $this->orderError($ids) ?? $this->fleetError($userId, $planet);
    }

    /**
     * @param array<int, int> $ids the planet and its moon
     */
    private function orderError(array $ids): ?string
    {
        if (DB::table('building_queues')->whereIn('planet_id', $ids)->exists()) {
            return 'rl_error_building';
        }

        if (DB::table('research_queues')->whereIn('planet_id', $ids)->exists()) {
            return 'rl_error_research';
        }

        foreach (DB::table('planets')->whereIn('planet_id', $ids)->pluck('planet_b_hangar_id') as $hangar) {
            if (trim(self::asString($hangar), ';') !== '') {
                return 'rl_error_shipyard';
            }
        }

        // the repaired ships wait in the space dock of the planet
        if (DB::table('wreck_fields')->where('planet_id', $ids[0] ?? 0)->where('repair_started_at', '>', 0)->exists()) {
            return 'rl_error_wreck_field';
        }

        return null;
    }

    private function fleetError(int $userId, \stdClass $planet): ?string
    {
        $flying = DB::table('fleets')
            ->where('fleet_owner', $userId)
            ->where(function ($query) use ($planet): void {
                $query->where(fn ($from) => $this->atPosition($from, 'fleet_start', $planet))
                    ->orWhere(fn ($to) => $this->atPosition($to, 'fleet_end', $planet));
            })
            ->exists();

        return $flying ? 'rl_error_fleets' : null;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     */
    private function atPosition($query, string $prefix, \stdClass $planet): void
    {
        $query->where($prefix . '_galaxy', (int) $planet->planet_galaxy)
            ->where($prefix . '_system', (int) $planet->planet_system)
            ->where($prefix . '_planet', (int) $planet->planet_planet);
    }

    /**
     * @param array{0: int, 1: int, 2: int} $target
     */
    private function settleError(int $userId, \stdClass $planet, array $target): ?string
    {
        if ((int) $planet->planet_destroyed !== 0) {
            return 'rl_error_planet';
        }

        if ($this->taken($target[0], $target[1], $target[2], (int) $planet->planet_id)) {
            return 'rl_error_taken';
        }

        $error = $this->hindrance($userId, $planet);

        if ($error !== null) {
            return $error;
        }

        $paid = DB::table('premium')
            ->where('premium_user_id', $userId)
            ->where('premium_dark_matter', '>=', $this->price())
            ->decrement('premium_dark_matter', $this->price());

        return $paid === 1 ? null : 'rl_error_dark_matter';
    }

    private function lockedPlanet(int $userId, int $planetId): ?\stdClass
    {
        return DB::table('planets')
            ->where('planet_id', $planetId)
            ->where('planet_user_id', $userId)
            ->where('planet_type', PlanetTypesEnumerator::PLANET)
            ->where('planet_destroyed', 0)
            ->lockForUpdate()
            ->first();
    }

    private function moonId(\stdClass $planet): int
    {
        return self::asInt(DB::table('planets')
            ->where('planet_galaxy', (int) $planet->planet_galaxy)
            ->where('planet_system', (int) $planet->planet_system)
            ->where('planet_planet', (int) $planet->planet_planet)
            ->where('planet_type', PlanetTypesEnumerator::MOON)
            ->value('planet_id'));
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param array<string, int>                 $values
     */
    private function clear($query, array $values = []): int
    {
        return $query->update($values + [
            'planet_relocate_galaxy' => 0,
            'planet_relocate_system' => 0,
            'planet_relocate_planet' => 0,
            'planet_relocate_at' => 0,
        ]);
    }

    /**
     * @param array{0: int, 1: int, 2: int} $coords
     */
    private function coords(array $coords): string
    {
        return '[' . implode(':', $coords) . ']';
    }

    /**
     * @param array<int, string> $replacements
     */
    private function tell(int $userId, int $time, string $key, array $replacements): void
    {
        Functions::sendMessage(
            $userId,
            0,
            $time,
            MessagesEnumerator::GENERAL,
            (string) __('game/planetlayer.rl_from'),
            (string) __('game/planetlayer.rl_subject'),
            vsprintf((string) __('game/planetlayer.' . $key), $replacements)
        );
    }
}
