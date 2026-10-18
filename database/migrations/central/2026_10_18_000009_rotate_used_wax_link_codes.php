<?php

declare(strict_types=1);

use App\Services\Auth\Totp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'central';

    /**
     * A link code counts once now: the codes of links made before are on
     * the chain for anyone to send again, so each gets a new one.
     */
    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('wax_links')) {
            return;
        }

        $table = DB::connection($this->connection)->table('wax_links');

        foreach ($table->clone()->whereNotNull('linked_at')->pluck('id') as $id) {
            $table->clone()->where('id', $id)->update(['code' => substr(strtolower(Totp::base32(random_bytes(10))), 0, 12)]);
        }
    }

    /**
     * Nothing to undo: the old codes are not wanted back.
     */
    public function down(): void
    {
    }
};
