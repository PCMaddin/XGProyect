<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Bots that play on their own: which users are bots and when each acts
     * next, and the switch for it. Bots created before had no mark; they
     * are the users with an address at bots.local.
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

        DB::table('users')->where('email', 'like', '%@bots.local')->update(['user_is_bot' => true]);

        Options::firstOrCreate(['name' => 'bots_play'], ['value' => '1', 'type' => 'int']);
        Options::firstOrCreate(['name' => 'bots_last_run'], ['value' => '0', 'type' => 'int']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'user_is_bot')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['user_is_bot', 'user_bot_next_action']);
                $table->dropColumn(['user_is_bot', 'user_bot_next_action']);
            });
        }

        Options::whereIn('name', ['bots_play', 'bots_last_run'])->delete();
    }
};
