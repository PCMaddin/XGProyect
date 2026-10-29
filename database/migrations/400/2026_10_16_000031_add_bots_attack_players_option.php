<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Whether bots may attack players, not only other bots; off until the
     * admin allows it.
     */
    public function up(): void
    {
        Options::firstOrCreate(['name' => 'bots_attack_players'], ['value' => '0', 'type' => 'int']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Options::where('name', 'bots_attack_players')->delete();
    }
};
