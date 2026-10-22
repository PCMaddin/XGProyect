<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Items: what each player owns, the boosters working on planets, and the
     * fields a planet or moon got from an item (once each).
     */
    public function up(): void
    {
        if (!Schema::hasTable('user_items')) {
            Schema::create('user_items', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->string('item', 40);
                $table->unsignedInteger('amount')->default(0);

                $table->primary(['user_id', 'item']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('planet_boosters')) {
            Schema::create('planet_boosters', function (Blueprint $table) {
                $table->unsignedBigInteger('planet_id');
                $table->string('resource', 10);
                $table->unsignedTinyInteger('percent');
                $table->unsignedInteger('starts_at');
                $table->unsignedInteger('ends_at');

                $table->primary(['planet_id', 'resource']);
                $table->foreign('planet_id')->references('planet_id')->on('planets')->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('planets', 'planet_item_fields')) {
            Schema::table('planets', function (Blueprint $table): void {
                $table->unsignedTinyInteger('planet_item_fields')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('planet_boosters');
        Schema::dropIfExists('user_items');

        if (Schema::hasColumn('planets', 'planet_item_fields')) {
            Schema::table('planets', function (Blueprint $table): void {
                $table->dropColumn('planet_item_fields');
            });
        }
    }
};
