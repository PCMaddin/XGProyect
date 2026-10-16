<?php

declare(strict_types=1);

use App\Libraries\Functions;
use App\Models\Options;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * The installer stored the server language as "spanish", which is no
     * language directory: the game fell back to English, the changelog showed
     * nothing and the server settings had no language selected. Such a value
     * becomes "en", so the game looks as before.
     */
    public function up(): void
    {
        $option = Options::where('name', 'lang')->first();

        if ($option !== null && !in_array($option->value, Functions::getLanguagesList(), true)) {
            $option->value = 'en';
            $option->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // the former value was invalid; nothing to restore
    }
};
