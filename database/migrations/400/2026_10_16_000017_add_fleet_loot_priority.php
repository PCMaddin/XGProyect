<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The plunder priority an admiral sets for an attack: the order in
     * which the fleet loads metal (m), crystal (c) and deuterium (d); empty
     * for the usual split.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('fleets', 'fleet_loot_priority')) {
            Schema::table('fleets', function (Blueprint $table): void {
                $table->string('fleet_loot_priority', 3)->default('');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fleets', 'fleet_loot_priority')) {
            Schema::table('fleets', function (Blueprint $table): void {
                $table->dropColumn('fleet_loot_priority');
            });
        }
    }
};
