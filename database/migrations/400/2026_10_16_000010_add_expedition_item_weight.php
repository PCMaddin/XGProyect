<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Expeditions can find items: 0,5 %, taken from "nothing" (1880 -> 1830)
     * so the results still add up to 100 %. Where an admin changed "nothing"
     * the weight starts at 0, the admin page then still saves.
     */
    public function up(): void
    {
        if (Options::where('name', 'expedition_result_item_weight')->exists()) {
            return;
        }

        $taken = Options::where('name', 'expedition_result_nothing_weight')->where('value', '1880')->update(['value' => '1830']);
        Options::create(['name' => 'expedition_result_item_weight', 'value' => $taken > 0 ? '50' : '0', 'type' => 'int']);
    }

    public function down(): void
    {
        $item = Options::where('name', 'expedition_result_item_weight')->value('value');

        if ($item === '50') {
            Options::where('name', 'expedition_result_nothing_weight')->where('value', '1830')->update(['value' => '1880']);
        }

        Options::where('name', 'expedition_result_item_weight')->delete();
    }
};
