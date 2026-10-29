<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Orders paid in a cryptocurrency (WAX): what the player was asked to
     * transfer, in the token's smallest unit (1 WAX = 100 000 000), and until
     * when that quote holds.
     */
    public function up(): void
    {
        Schema::table('dark_matter_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('dark_matter_orders', 'provider_amount')) {
                $table->unsignedBigInteger('provider_amount')->nullable();
            }

            if (!Schema::hasColumn('dark_matter_orders', 'provider_currency')) {
                $table->string('provider_currency', 8)->nullable();
            }

            if (!Schema::hasColumn('dark_matter_orders', 'expires_at')) {
                $table->unsignedInteger('expires_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dark_matter_orders', function (Blueprint $table) {
            $table->dropColumn(['provider_amount', 'provider_currency', 'expires_at']);
        });
    }
};
