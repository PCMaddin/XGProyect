<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Pay2Win (dark matter can be bought) or Play2Win (only found on
     * expeditions or given by the admin); a universe without a shop is
     * Play2Win.
     */
    public function up(): void
    {
        Options::firstOrCreate(['name' => 'premium_mode'], ['value' => 'play2win', 'type' => 'string']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Options::where('name', 'premium_mode')->delete();
    }
};
