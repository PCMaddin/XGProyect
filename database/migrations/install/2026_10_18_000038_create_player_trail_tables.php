<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * What the cheat detection looks at (App\Services\Security): sign-ins
     * with address, browser and device, resources sent between players,
     * requests per hour; and what it found, for the admin to review.
     */
    public function up(): void
    {
        if (!Schema::hasTable('login_events')) {
            Schema::create('login_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('ip', 45);
                $table->string('agent', 255)->default('');
                // a random id kept in a cookie of the browser
                $table->string('device', 32)->index();
                $table->unsignedInteger('created_at')->index();
            });
        }

        if (!Schema::hasTable('resource_transfers')) {
            Schema::create('resource_transfers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('from_user')->index();
                $table->unsignedBigInteger('to_user')->index();
                $table->double('metal')->default(0);
                $table->double('crystal')->default(0);
                $table->double('deuterium')->default(0);
                $table->unsignedInteger('created_at')->index();
            });
        }

        if (!Schema::hasTable('activity_hours')) {
            Schema::create('activity_hours', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                // unix time / 3600
                $table->unsignedInteger('hour');
                $table->unsignedInteger('requests')->default(0);
                $table->primary(['user_id', 'hour']);
            });
        }

        if (!Schema::hasTable('suspicions')) {
            Schema::create('suspicions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('kind', 24);
                // the other account of a pair; 0 for one account alone
                $table->unsignedBigInteger('other_user')->default(0);
                $table->unsignedTinyInteger('score');
                $table->text('detail');
                $table->string('status', 16)->default('open');
                $table->text('note')->nullable();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->unsignedInteger('reviewed_at')->nullable();

                $table->unique(['user_id', 'kind', 'other_user']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suspicions');
        Schema::dropIfExists('activity_hours');
        Schema::dropIfExists('resource_transfers');
        Schema::dropIfExists('login_events');
    }
};
