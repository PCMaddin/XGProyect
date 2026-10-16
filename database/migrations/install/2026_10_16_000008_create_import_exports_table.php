<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Import/export: each player's container of the day (the item inside,
     * how often it was swapped, whether it was bought).
     */
    public function up(): void
    {
        if (Schema::hasTable('import_exports')) {
            return;
        }

        Schema::create('import_exports', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->date('day');
            $table->string('item', 40);
            $table->unsignedTinyInteger('swaps')->default(0);
            $table->boolean('bought')->default(false);

            $table->primary(['user_id', 'day']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_exports');
    }
};
