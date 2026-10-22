<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Debris fields in deep space (position 16, OGame 7): left by
     * expedition battles, harvested by pathfinders.
     */
    public function up(): void
    {
        if (!Schema::hasTable('deep_space_debris')) {
            Schema::create('deep_space_debris', function (Blueprint $table) {
                $table->unsignedTinyInteger('galaxy');
                $table->unsignedSmallInteger('system');
                $table->double('metal')->default(0);
                $table->double('crystal')->default(0);
                $table->unsignedInteger('updated_at')->default(0);

                $table->primary(['galaxy', 'system']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deep_space_debris');
    }
};
