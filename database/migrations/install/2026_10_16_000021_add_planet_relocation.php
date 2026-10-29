<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Relocating a planet (original "Umsiedeln"): the reserved target
     * position and when the relocation is done (the options seeder brings
     * its price).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('planets', 'planet_relocate_at')) {
            Schema::table('planets', function (Blueprint $table): void {
                $table->integer('planet_relocate_galaxy')->default(0);
                $table->integer('planet_relocate_system')->default(0);
                $table->integer('planet_relocate_planet')->default(0);
                $table->integer('planet_relocate_at')->default(0)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('planets', 'planet_relocate_at')) {
            Schema::table('planets', function (Blueprint $table): void {
                $table->dropIndex(['planet_relocate_at']);
                $table->dropColumn(['planet_relocate_galaxy', 'planet_relocate_system', 'planet_relocate_planet', 'planet_relocate_at']);
            });
        }
    }
};
