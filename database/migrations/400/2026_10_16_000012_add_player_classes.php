<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Player classes (OGame 7): the class of each player (0 = none yet, the
     * first choice is free), the crawler setting of each planet, and the
     * dark matter a class change costs.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'player_class')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedTinyInteger('player_class')->default(0);
            });
        }

        // the crawlers' setting in tenths, up to 15 (150 %) for collectors
        if (!Schema::hasColumn('planets', 'planet_ship_crawler_percent')) {
            Schema::table('planets', function (Blueprint $table): void {
                $table->integer('planet_ship_crawler_percent')->default(10);
            });
        }

        Options::firstOrCreate(['name' => 'class_change_price'], ['value' => '500000', 'type' => 'int']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'player_class')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('player_class');
            });
        }

        if (Schema::hasColumn('planets', 'planet_ship_crawler_percent')) {
            Schema::table('planets', function (Blueprint $table): void {
                $table->dropColumn('planet_ship_crawler_percent');
            });
        }

        Options::where('name', 'class_change_price')->delete();
    }
};
