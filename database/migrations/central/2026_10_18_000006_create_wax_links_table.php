<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * A player's account in a universe linked to a WAX account: the one
     * identity across the universes, which season prizes are paid to. The
     * player proves the WAX account by a transfer with the code as memo.
     */
    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('wax_links')) {
            Schema::connection($this->connection)->create('wax_links', function (Blueprint $table) {
                $table->id();
                // empty on a server without universes
                $table->string('universe', 32)->default('');
                $table->unsignedBigInteger('user_id');
                $table->string('code', 16)->unique();
                $table->string('wax_account', 13)->nullable()->index();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('linked_at')->nullable();

                $table->unique(['universe', 'user_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wax_links');
    }
};
