<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * When a universe's season ends; empty for one that runs on.
     */
    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasColumn('instances', 'ends_at')) {
            Schema::connection($this->connection)->table('instances', function (Blueprint $table) {
                $table->unsignedInteger('ends_at')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('instances', function (Blueprint $table) {
            $table->dropColumn('ends_at');
        });
    }
};
