<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Relocating a planet (original "Umsiedeln"): the reserved target
     * position and when the relocation is done; its price in dark matter.
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

        Options::firstOrCreate(['name' => 'relocation_price'], ['value' => '240000', 'type' => 'int']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('planets', 'planet_relocate_at')) {
            Schema::table('planets', function (Blueprint $table): void {
                $table->dropIndex(['planet_relocate_at']);
                $table->dropColumn(['planet_relocate_galaxy', 'planet_relocate_system', 'planet_relocate_planet', 'planet_relocate_at']);
            });
        }

        Options::where('name', 'relocation_price')->delete();
    }
};
