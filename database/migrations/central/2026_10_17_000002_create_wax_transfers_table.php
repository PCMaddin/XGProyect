<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * Every WAX transfer to the shop's account, once, with what came of it
     * (see App\Services\Premium\WaxPayments). One account serves all
     * universes, so the transfers are kept in the base database; the memo
     * names the universe and the order.
     */
    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('wax_transfers')) {
            Schema::connection($this->connection)->create('wax_transfers', function (Blueprint $table) {
                $table->id();
                // the chain's number of the action: unique, a transfer counts once
                $table->unsignedBigInteger('sequence')->unique();
                $table->string('trx_id', 64);
                $table->unsignedInteger('block_num');
                $table->unsignedInteger('block_time');
                $table->string('from_account', 13);
                // 1 WAX = 100 000 000
                $table->unsignedBigInteger('units');
                $table->string('memo', 256);
                $table->string('universe', 32)->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('result', 16);
                $table->unsignedInteger('checked_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wax_transfers');
    }
};
