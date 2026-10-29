<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * Each run of xgp:backup:run: when, which databases, and how it ended.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (!$schema->hasTable('backup_runs')) {
            $schema->create('backup_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('started_at')->index();
                $table->unsignedInteger('finished_at')->nullable();
                $table->string('status', 10);
                $table->text('databases');
                $table->text('message');
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('backup_runs');
    }
};
