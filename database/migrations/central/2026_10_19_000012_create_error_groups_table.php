<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * The errors of every universe, one row per kind (class, place and the
     * message without its numbers): how often, where last, since when.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (!$schema->hasTable('error_groups')) {
            $schema->create('error_groups', function (Blueprint $table) {
                $table->id();
                $table->char('fingerprint', 40)->unique();
                $table->string('class', 190);
                $table->text('message');
                $table->string('file', 300);
                $table->unsignedInteger('line');
                $table->string('universe', 32)->default('');
                $table->string('context', 300)->default('');
                $table->mediumText('trace');
                $table->unsignedInteger('occurrences')->default(1);
                $table->unsignedInteger('pending')->default(1);
                $table->string('status', 10)->default('open');
                $table->unsignedInteger('first_seen');
                $table->unsignedInteger('last_seen');
                $table->unsignedInteger('notified_at')->nullable();
                $table->index(['status', 'last_seen']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('error_groups');
    }
};
