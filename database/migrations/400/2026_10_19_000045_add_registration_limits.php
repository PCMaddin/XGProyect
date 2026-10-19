<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Registration only with an invitation code (reg_invite_only), and up to
     * a number of players (reg_max_players, 0: no limit). Both off.
     */
    public function up(): void
    {
        Options::firstOrCreate(['name' => 'reg_invite_only'], ['value' => '0', 'type' => 'int']);
        Options::firstOrCreate(['name' => 'reg_max_players'], ['value' => '0', 'type' => 'int']);
    }

    public function down(): void
    {
        Options::whereIn('name', ['reg_invite_only', 'reg_max_players'])->delete();
    }
};
