<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * The legal pages of the server (imprint, privacy policy, terms,
     * withdrawal), one text per page and language, written by the operators
     * on /operator and the same in every universe.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (!$schema->hasTable('legal_pages')) {
            $schema->create('legal_pages', function (Blueprint $table) {
                $table->id();
                $table->string('page', 16);
                $table->string('locale', 8);
                $table->mediumText('body');
                $table->string('updated_by', 254);
                $table->unsignedInteger('updated_at');
                $table->unique(['page', 'locale']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('legal_pages');
    }
};
