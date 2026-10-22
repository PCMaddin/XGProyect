<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The space dock (OGame 7) and the wreck fields it repairs: one per
     * planet, with the wrecked ships (id => amount), when it burns up, from
     * when it can be repaired (attackers' wrecks once their fleet is back),
     * and the running repair with the ships it brings back.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('buildings', 'building_space_dock')) {
            Schema::table('buildings', function (Blueprint $table): void {
                $table->integer('building_space_dock')->default(0);
            });
        }

        if (!Schema::hasTable('wreck_fields')) {
            Schema::create('wreck_fields', function (Blueprint $table) {
                $table->unsignedBigInteger('planet_id')->primary();
                $table->unsignedBigInteger('user_id')->index();
                $table->text('ships');
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('expires_at');
                $table->unsignedInteger('repairable_from')->default(0);
                $table->unsignedInteger('repair_started_at')->default(0);
                $table->unsignedInteger('repair_ends_at')->default(0);
                $table->text('repaired')->nullable();

                $table->foreign('planet_id')->references('planet_id')->on('planets')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wreck_fields');

        if (Schema::hasColumn('buildings', 'building_space_dock')) {
            Schema::table('buildings', function (Blueprint $table): void {
                $table->dropColumn('building_space_dock');
            });
        }
    }
};
