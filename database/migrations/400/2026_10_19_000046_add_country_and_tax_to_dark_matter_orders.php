<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Where the buyer paid from and how much of the price was tax, as Stripe
     * reports them with the payment: the books need the country for VAT
     * (digital content is taxed where the buyer lives) and the tax amount.
     * Null for orders paid another way or before this was kept.
     */
    public function up(): void
    {
        Schema::table('dark_matter_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('dark_matter_orders', 'country')) {
                $table->char('country', 2)->nullable();
            }

            if (!Schema::hasColumn('dark_matter_orders', 'tax')) {
                $table->unsignedInteger('tax')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dark_matter_orders', function (Blueprint $table) {
            $table->dropColumn(['country', 'tax']);
        });
    }
};
