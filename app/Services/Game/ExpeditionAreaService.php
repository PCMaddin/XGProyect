<?php

declare(strict_types=1);

namespace App\Services\Game;

use Illuminate\Support\Facades\DB;

/**
 * How busy expedition areas are, as in OGame (owiki.de, "Expedition").
 *
 * Each expedition adds 1 to the load of its solar system, which recovers by
 * 10 a day. Up to 10 expeditions a day an area stays fresh; above, ever more
 * expeditions find nothing, which a longer holding time counteracts. A
 * probe in the fleet reports how busy the area is (logbook entry).
 *
 * The wiki names the steps and the recovery but no formula: the chance of
 * finding nothing grows by 2 % per expedition above 10, up to 90 %, and each
 * hour of holding time beyond the first is one more try.
 */
class ExpeditionAreaService
{
    /** expeditions an area recovers from per day */
    public const RECOVERY_PER_DAY = 10;

    private const FRESH = 10;
    private const BUSY = 25;
    private const CROWDED = 50;

    private const NOTHING_PER_EXPEDITION = 0.02;
    private const MAX_NOTHING = 0.9;

    /**
     * An expedition arrives: the load of the area before it, which then
     * grows by one.
     */
    public function visit(int $galaxy, int $system, int $time): float
    {
        return DB::transaction(function () use ($galaxy, $system, $time): float {
            $area = DB::table('expedition_areas')
                ->where('galaxy', $galaxy)
                ->where('system', $system)
                ->lockForUpdate()
                ->first();

            $load = $area === null ? 0.0 : self::recovered((float) $area->load, (int) $area->updated_at, $time);
            $updatedAt = $area === null ? $time : max((int) $area->updated_at, $time);

            DB::table('expedition_areas')->updateOrInsert(
                ['galaxy' => $galaxy, 'system' => $system],
                ['load' => $load + 1, 'updated_at' => $updatedAt]
            );

            return $load;
        });
    }

    /**
     * The load left at $time: it drops by 10 a day.
     */
    public static function recovered(float $load, int $since, int $time): float
    {
        return max(0.0, $load - self::RECOVERY_PER_DAY * max(0, $time - $since) / 86400);
    }

    /**
     * The chance that an expedition in an area with this load finds
     * nothing because of it, after holding $holdingTime seconds.
     */
    public function nothingChance(float $load, int $holdingTime): float
    {
        $chance = min(self::MAX_NOTHING, max(0.0, ($load - self::FRESH) * self::NOTHING_PER_EXPEDITION));
        $hours = max(1, intdiv($holdingTime, 3600));

        return $chance ** $hours;
    }

    /**
     * The language key of the logbook entry for an area with this load.
     */
    public function logbookKey(float $load): string
    {
        return match (true) {
            $load <= self::FRESH => 'exp_status_fresh_' . mt_rand(1, 2),
            $load <= self::BUSY => 'exp_status_busy',
            $load <= self::CROWDED => 'exp_status_crowded',
            default => 'exp_status_overrun',
        };
    }
}
