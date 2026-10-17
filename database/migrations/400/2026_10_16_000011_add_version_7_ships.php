<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The ships of OGame 7: the battlecruiser is back at id 215, the reaper
     * moves to 218 (its own id in the original), crawler 217 and pathfinder
     * 219 are new. The reapers built so far stay reapers (column
     * ship_reaper); fleets in flight and shipyard orders that name 215 now
     * name 218.
     */
    public function up(): void
    {
        if (Schema::hasColumn('ships', 'ship_battlecruiser')) {
            return;
        }

        Schema::table('ships', function (Blueprint $table): void {
            $table->integer('ship_battlecruiser')->default(0);
            $table->integer('ship_crawler')->default(0);
            $table->integer('ship_pathfinder')->default(0);
        });

        foreach (DB::table('fleets')->select(['fleet_id', 'fleet_array'])->get() as $fleet) {
            $ships = @unserialize((string) $fleet->fleet_array, ['allowed_classes' => false]);

            if (is_array($ships) && isset($ships[215])) {
                $ships[218] = ($ships[218] ?? 0) + $ships[215];
                unset($ships[215]);
                DB::table('fleets')->where('fleet_id', $fleet->fleet_id)->update(['fleet_array' => serialize($ships)]);
            }
        }

        foreach (DB::table('planets')->where('planet_b_hangar_id', 'like', '%215,%')->select(['planet_id', 'planet_b_hangar_id'])->get() as $planet) {
            DB::table('planets')->where('planet_id', $planet->planet_id)->update([
                'planet_b_hangar_id' => preg_replace('/(^|;)215,/', '${1}218,', (string) $planet->planet_b_hangar_id),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('ships', 'ship_battlecruiser')) {
            return;
        }

        Schema::table('ships', function (Blueprint $table): void {
            $table->dropColumn(['ship_battlecruiser', 'ship_crawler', 'ship_pathfinder']);
        });
    }
};
