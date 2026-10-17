<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Alliance classes (OGame 7): the class of each alliance (0 = none yet,
     * the first choice is free, a change costs class_change_price).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('alliance', 'alliance_class')) {
            Schema::table('alliance', function (Blueprint $table): void {
                $table->unsignedTinyInteger('alliance_class')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('alliance', 'alliance_class')) {
            Schema::table('alliance', function (Blueprint $table): void {
                $table->dropColumn('alliance_class');
            });
        }
    }
};
