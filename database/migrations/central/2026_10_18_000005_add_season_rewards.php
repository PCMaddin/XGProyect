<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * Season rewards: each universe keeps the reward plan it started with
     * (dark matter per ranking, shared out over the places); each frozen
     * place keeps its reward, the WAX account it is paid to and when and
     * where it was redeemed.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (!$schema->hasColumn('instances', 'reward_plan')) {
            $schema->table('instances', function (Blueprint $table) {
                $table->text('reward_plan')->nullable();
            });
        }

        if (!$schema->hasColumn('season_results', 'reward')) {
            $schema->table('season_results', function (Blueprint $table) {
                $table->unsignedInteger('reward')->default(0);
                $table->string('wax_account', 13)->nullable()->index();
                $table->unsignedInteger('claimed_at')->nullable();
                $table->string('claimed_universe', 32)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('season_results', function (Blueprint $table) {
            $table->dropColumn(['reward', 'wax_account', 'claimed_at', 'claimed_universe']);
        });
        Schema::connection($this->connection)->table('instances', function (Blueprint $table) {
            $table->dropColumn('reward_plan');
        });
    }
};
