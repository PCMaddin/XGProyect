<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Invitation codes: with reg_invite_only a player registers only with
     * one, each good for a number of players and maybe until a date.
     */
    public function up(): void
    {
        if (!Schema::hasTable('invitations')) {
            Schema::create('invitations', function (Blueprint $table) {
                $table->id();
                $table->string('code', 16)->unique();
                $table->unsignedInteger('uses');
                $table->unsignedInteger('used')->default(0);
                $table->unsignedInteger('expires_at')->nullable();
                $table->string('note', 100)->default('');
                $table->string('created_by', 64);
                $table->unsignedInteger('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
