<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Dark matter bought with real money (Pay2Win universes): an order is
     * pending until the payment provider confirms it by webhook, then paid
     * (credited once); expired, failed or refunded otherwise. Times are unix
     * seconds like everywhere in the game. No foreign key to the users: the
     * orders stay for the accounts after a player is deleted.
     */
    public function up(): void
    {
        if (!Schema::hasTable('dark_matter_orders')) {
            Schema::create('dark_matter_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('package', 32);
                $table->unsignedInteger('dark_matter');
                // in the smallest unit of the currency (cents)
                $table->unsignedInteger('amount');
                $table->string('currency', 3);
                $table->string('provider', 16)->default('stripe');
                // the checkout session (cs_…) and the payment (pi_…) at the provider
                $table->string('provider_session', 255)->nullable()->unique();
                $table->string('provider_payment', 255)->nullable()->index();
                $table->string('status', 16)->default('pending');
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('paid_at')->nullable();
                $table->unsignedInteger('refunded_at')->nullable();

                $table->index(['user_id', 'status']);
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dark_matter_orders');
    }
};
