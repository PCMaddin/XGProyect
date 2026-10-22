<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * When a player last sent probes to a planet or moon, for the system
     * espionage of warrior alliances (OGame 8), which skips targets spied
     * on recently.
     */
    public function up(): void
    {
        if (!Schema::hasTable('espionage_log')) {
            Schema::create('espionage_log', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('planet_id');
                $table->unsignedInteger('spied_at');

                $table->primary(['user_id', 'planet_id']);
                $table->foreign('planet_id')->references('planet_id')->on('planets')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('espionage_log');
    }
};
