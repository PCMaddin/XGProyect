<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Honour points of the original: won in honourable fights, lost in
     * dishonourable ones; their rank gives the titles (Starlord … Bandit).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('users_statistics', 'user_statistic_honor_points')) {
            Schema::table('users_statistics', function (Blueprint $table): void {
                $table->double('user_statistic_honor_points')->default(0);
                $table->integer('user_statistic_honor_old_rank')->default(0);
                $table->integer('user_statistic_honor_rank')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users_statistics', 'user_statistic_honor_points')) {
            Schema::table('users_statistics', function (Blueprint $table): void {
                $table->dropColumn(['user_statistic_honor_points', 'user_statistic_honor_old_rank', 'user_statistic_honor_rank']);
            });
        }
    }
};
