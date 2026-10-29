<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * The operators of the server: they manage the universes on /operator,
     * apart from the accounts of any universe, always with two-factor
     * sign-in; and what they did there.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (!$schema->hasTable('operators')) {
            $schema->create('operators', function (Blueprint $table) {
                $table->id();
                $table->string('email', 254)->unique();
                $table->string('password');
                $table->text('two_factor_secret')->nullable();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->unsignedInteger('two_factor_confirmed_at')->nullable();
                $table->unsignedBigInteger('two_factor_last_step')->nullable();
                $table->unsignedInteger('last_login_at')->nullable();
                $table->string('last_login_ip', 45)->nullable();
                $table->unsignedInteger('created_at');
            });
        }

        if (!$schema->hasTable('operator_events')) {
            $schema->create('operator_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('operator_id')->nullable();
                $table->string('email', 254);
                $table->string('action', 32);
                $table->string('subject', 64)->nullable();
                $table->text('details')->nullable();
                $table->string('ip', 45);
                $table->unsignedInteger('created_at')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('operator_events');
        Schema::connection($this->connection)->dropIfExists('operators');
    }
};
