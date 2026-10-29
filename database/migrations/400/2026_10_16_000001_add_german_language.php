<?php

declare(strict_types=1);

use App\Models\Changelog;
use App\Models\Languages;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Languages::where('code', 'de')->exists()) {
            Languages::insert(['name' => 'Deutsch', 'code' => 'de']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $language = Languages::where('code', 'de')->first();

        // keep it while changelog entries are written in it
        if ($language !== null && !Changelog::where('changelog_lang_id', $language->id)->exists()) {
            $language->delete();
        }
    }
};
