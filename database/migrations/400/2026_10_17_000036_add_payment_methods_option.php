<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * How dark matter is paid in a Pay2Win universe: stripe, wax or both,
     * separated by commas.
     */
    public function up(): void
    {
        Options::firstOrCreate(['name' => 'payment_methods'], ['value' => 'stripe', 'type' => 'string']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Options::where('name', 'payment_methods')->delete();
    }
};
