<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * How busy the expedition area (position 16) of each solar system is:
     * the expeditions it saw, recovering by 10 a day (ExpeditionAreaService).
     */
    public function up(): void
    {
        if (Schema::hasTable('expedition_areas')) {
            return;
        }

        Schema::create('expedition_areas', function (Blueprint $table) {
            $table->unsignedSmallInteger('galaxy');
            $table->unsignedSmallInteger('system');
            $table->double('load')->default(0);
            $table->unsignedInteger('updated_at')->default(0);

            $table->primary(['galaxy', 'system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expedition_areas');
    }
};
