<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * The universes of the server (see App\Services\Instances\InstanceRegistry).
     */
    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('instances')) {
            Schema::connection($this->connection)->create('instances', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 32)->unique();
                $table->string('name', 64);
                $table->string('database', 64);
                $table->string('mode', 16)->default('play2win');
                $table->string('status', 16)->default('open');
                $table->unsignedInteger('opened_at')->default(0);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('instances');
    }
};
