<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The auctioneer: one auction an hour, and the bids of the players
     * (their total in metal units).
     */
    public function up(): void
    {
        if (!Schema::hasTable('auctions')) {
            Schema::create('auctions', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('starts_at')->unique();
                $table->unsignedInteger('ends_at');
                $table->string('item', 40);
                $table->unsignedBigInteger('winner_id')->nullable();
                $table->boolean('settled')->default(false);
            });
        }

        if (!Schema::hasTable('auction_bids')) {
            Schema::create('auction_bids', function (Blueprint $table) {
                $table->unsignedBigInteger('auction_id');
                $table->unsignedBigInteger('user_id');
                $table->double('value')->default(0);
                $table->unsignedInteger('updated_at');

                $table->primary(['auction_id', 'user_id']);
                $table->foreign('auction_id')->references('id')->on('auctions')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_bids');
        Schema::dropIfExists('auctions');
    }
};
