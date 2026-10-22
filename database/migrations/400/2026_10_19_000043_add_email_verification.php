<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * New players confirm their email address (reg_verify_email). Everyone
     * already playing counts as confirmed: nobody is locked out or deleted
     * by the update.
     */
    public function up(): void
    {
        Options::firstOrCreate(['name' => 'reg_verify_email'], ['value' => '1', 'type' => 'int']);
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Options::where('name', 'reg_verify_email')->delete();
    }
};
