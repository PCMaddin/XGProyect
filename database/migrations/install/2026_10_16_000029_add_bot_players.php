<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Bots that play on their own: which users are bots and when each acts
     * next (the options seeder brings the switch).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'user_is_bot')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('user_is_bot')->default(false);
                $table->integer('user_bot_next_action')->default(0);
                $table->index(['user_is_bot', 'user_bot_next_action']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'user_is_bot')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['user_is_bot', 'user_bot_next_action']);
                $table->dropColumn(['user_is_bot', 'user_bot_next_action']);
            });
        }
    }
};
