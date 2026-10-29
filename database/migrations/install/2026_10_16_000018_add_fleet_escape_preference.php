<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Whether the player's fleet flees from a far stronger attacker
     * (on by default; off for moon shots, as in the original).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('preferences', 'preference_fleet_escape')) {
            Schema::table('preferences', function (Blueprint $table): void {
                $table->boolean('preference_fleet_escape')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('preferences', 'preference_fleet_escape')) {
            Schema::table('preferences', function (Blueprint $table): void {
                $table->dropColumn('preference_fleet_escape');
            });
        }
    }
};
