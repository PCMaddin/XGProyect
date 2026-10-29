<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * The rankings of a universe as they stood when its season ended (see
     * App\Services\Instances\SeasonResults), kept in the base database for
     * the hall of fame after the universe is gone.
     */
    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('season_results')) {
            Schema::connection($this->connection)->create('season_results', function (Blueprint $table) {
                $table->id();
                $table->string('universe', 32);
                $table->string('category', 16);
                $table->unsignedTinyInteger('rank');
                $table->unsignedBigInteger('user_id');
                $table->string('name', 64);
                $table->string('alliance_tag', 8)->nullable();
                $table->double('points');
                $table->unsignedInteger('frozen_at');

                $table->unique(['universe', 'category', 'rank']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('season_results');
    }
};
