<?php

declare(strict_types=1);

use App\Models\Changelog;
use App\Models\Languages;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Português (Brasil) (pt_BR) joins the languages (lang/pt_BR): its record lets
     * changelog entries be written in it. New installs get it from the seeder.
     */
    public function up(): void
    {
        if (!Languages::where('code', 'pt_BR')->exists()) {
            Languages::insert(['name' => 'Português (Brasil)', 'code' => 'pt_BR']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $language = Languages::where('code', 'pt_BR')->first();

        // keep it while changelog entries are written in it
        if ($language !== null && !Changelog::where('changelog_lang_id', $language->id)->exists()) {
            $language->delete();
        }
    }
};
