<?php

declare(strict_types=1);

use App\Models\Changelog;
use App\Models\Languages;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * French joins the languages (lang/fr): its record lets changelog
     * entries be written in French. New installs get it from the seeder.
     */
    public function up(): void
    {
        if (!Languages::where('code', 'fr')->exists()) {
            Languages::insert(['name' => 'Français', 'code' => 'fr']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $language = Languages::where('code', 'fr')->first();

        // keep it while changelog entries are written in it
        if ($language !== null && !Changelog::where('changelog_lang_id', $language->id)->exists()) {
            $language->delete();
        }
    }
};
