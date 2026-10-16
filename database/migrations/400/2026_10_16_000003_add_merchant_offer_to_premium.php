<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The merchant's current offer (resource sold, factor), kept until an
     * exchange uses it.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('premium', 'premium_merchant_offer')) {
            Schema::table('premium', function (Blueprint $table): void {
                $table->string('premium_merchant_offer', 64)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('premium', 'premium_merchant_offer')) {
            Schema::table('premium', function (Blueprint $table): void {
                $table->dropColumn('premium_merchant_offer');
            });
        }
    }
};
