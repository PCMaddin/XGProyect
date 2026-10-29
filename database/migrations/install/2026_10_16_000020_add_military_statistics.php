<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The military rankings of the original: points of the units built,
     * destroyed (of the enemies) and lost, with their ranks.
     */
    public function up(): void
    {
        foreach (['built', 'destroyed', 'lost'] as $kind) {
            if (!Schema::hasColumn('users_statistics', 'user_statistic_military_' . $kind . '_points')) {
                Schema::table('users_statistics', function (Blueprint $table) use ($kind): void {
                    $table->double('user_statistic_military_' . $kind . '_points')->default(0);
                    $table->integer('user_statistic_military_' . $kind . '_old_rank')->default(0);
                    $table->integer('user_statistic_military_' . $kind . '_rank')->default(0);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['built', 'destroyed', 'lost'] as $kind) {
            if (Schema::hasColumn('users_statistics', 'user_statistic_military_' . $kind . '_points')) {
                Schema::table('users_statistics', function (Blueprint $table) use ($kind): void {
                    $table->dropColumn([
                        'user_statistic_military_' . $kind . '_points',
                        'user_statistic_military_' . $kind . '_old_rank',
                        'user_statistic_military_' . $kind . '_rank',
                    ]);
                });
            }
        }
    }
};
