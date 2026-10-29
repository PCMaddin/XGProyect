<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * The token of a transfer: WAX, or the game's own token.
     */
    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasColumn('wax_transfers', 'symbol')) {
            Schema::connection($this->connection)->table('wax_transfers', function (Blueprint $table) {
                $table->string('symbol', 8)->default('WAX');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wax_transfers', function (Blueprint $table) {
            $table->dropColumn('symbol');
        });
    }
};
