<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * The version of the code a universe plays on. Universes registered
     * before this ran on whatever was deployed: they get the version that
     * runs now.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasColumn('instances', 'version')) {
            return;
        }

        $schema->table('instances', function (Blueprint $table) {
            $table->string('version', 32)->default('');
        });

        $current = config('version.files');
        DB::connection($this->connection)->table('instances')->where('version', '')
            ->update(['version' => is_string($current) ? $current : '']);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('instances', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
