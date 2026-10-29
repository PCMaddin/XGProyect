<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * When a universe was closed and archived (its database dumped and
     * dropped), and where the dump lies: restic:<snapshot> or file:<path>.
     * Universes closed before count as closed when their season ended.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (!$schema->hasColumn('instances', 'closed_at')) {
            $schema->table('instances', function (Blueprint $table) {
                $table->unsignedInteger('closed_at')->nullable();
                $table->unsignedInteger('archived_at')->nullable();
                $table->string('archive', 255)->default('');
            });

            DB::connection($this->connection)->table('instances')->where('status', 'closed')
                ->update(['closed_at' => DB::raw('COALESCE(ends_at, ' . time() . ')')]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('instances', function (Blueprint $table) {
            $table->dropColumn(['closed_at', 'archived_at', 'archive']);
        });
    }
};
