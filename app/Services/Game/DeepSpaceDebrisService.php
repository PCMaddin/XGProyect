<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Enums\PlayerClass;
use Illuminate\Support\Facades\DB;

/**
 * Debris fields in deep space, at position 16 (OGame 7): battles against
 * pirates and aliens on expeditions leave 10 % of the destroyed ships'
 * metal and crystal there (20 % for discoverers, changelog 7.1). Only
 * pathfinders can harvest them, and only discoverers see them in the
 * galaxy.
 */
class DeepSpaceDebrisService
{
    public const POSITION = MAX_PLANET_IN_SYSTEM + 1;

    public function share(PlayerClass $class): float
    {
        return $class === PlayerClass::Discoverer ? 0.2 : 0.1;
    }

    public function add(int $galaxy, int $system, float $metal, float $crystal, int $now): void
    {
        $metal = floor(max(0, $metal));
        $crystal = floor(max(0, $crystal));

        if ($metal + $crystal <= 0) {
            return;
        }

        DB::transaction(function () use ($galaxy, $system, $metal, $crystal, $now): void {
            $updated = DB::table('deep_space_debris')->where('galaxy', $galaxy)->where('system', $system)->update([
                'metal' => DB::raw('`metal` + ' . $metal),
                'crystal' => DB::raw('`crystal` + ' . $crystal),
                'updated_at' => $now,
            ]);

            if ($updated === 0) {
                DB::table('deep_space_debris')->insert(['galaxy' => $galaxy, 'system' => $system, 'metal' => $metal, 'crystal' => $crystal, 'updated_at' => $now]);
            }
        });
    }

    /**
     * @return array{metal: float, crystal: float}
     */
    public function get(int $galaxy, int $system): array
    {
        $row = DB::table('deep_space_debris')->where('galaxy', $galaxy)->where('system', $system)->first(['metal', 'crystal']);

        $metal = $row === null ? 0 : $row->metal;
        $crystal = $row === null ? 0 : $row->crystal;

        return [
            'metal' => is_numeric($metal) ? (float) $metal : 0.0,
            'crystal' => is_numeric($crystal) ? (float) $crystal : 0.0,
        ];
    }

    /**
     * Take metal and crystal out of the field (what the pathfinders load).
     */
    public function take(int $galaxy, int $system, float $metal, float $crystal): void
    {
        DB::table('deep_space_debris')->where('galaxy', $galaxy)->where('system', $system)->update([
            'metal' => DB::raw('CASE WHEN `metal` > ' . $metal . ' THEN `metal` - ' . $metal . ' ELSE 0 END'),
            'crystal' => DB::raw('CASE WHEN `crystal` > ' . $crystal . ' THEN `crystal` - ' . $crystal . ' ELSE 0 END'),
        ]);
        DB::table('deep_space_debris')->where('galaxy', $galaxy)->where('system', $system)->where('metal', '<=', 0)->where('crystal', '<=', 0)->delete();
    }
}
