<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * What players report from the game, from every universe, for the
     * operators (new, seen, fixed).
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (!$schema->hasTable('bug_reports')) {
            $schema->create('bug_reports', function (Blueprint $table) {
                $table->id();
                $table->string('universe', 32)->default('');
                $table->unsignedBigInteger('user_id');
                $table->string('user_name', 64);
                $table->string('page', 500);
                $table->text('description');
                $table->string('user_agent', 300)->default('');
                $table->string('status', 16)->default('new');
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('bug_reports');
    }
};
