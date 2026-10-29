<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * When the player asked for the dark matter at once and so gave up the
     * right of withdrawal (digital content): the proof, kept with the order.
     */
    public function up(): void
    {
        Schema::table('dark_matter_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('dark_matter_orders', 'withdrawal_waived_at')) {
                $table->unsignedInteger('withdrawal_waived_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dark_matter_orders', function (Blueprint $table) {
            $table->dropColumn('withdrawal_waived_at');
        });
    }
};
