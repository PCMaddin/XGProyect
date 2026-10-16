<?php

declare(strict_types=1);

use App\Models\Changelog;
use App\Models\Languages;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * 简体中文 (zh_CN) joins the languages (lang/zh_CN): its record lets
     * changelog entries be written in it. New installs get it from the seeder.
     */
    public function up(): void
    {
        if (!Languages::where('code', 'zh_CN')->exists()) {
            Languages::insert(['name' => '简体中文', 'code' => 'zh_CN']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $language = Languages::where('code', 'zh_CN')->first();

        // keep it while changelog entries are written in it
        if ($language !== null && !Changelog::where('changelog_lang_id', $language->id)->exists()) {
            $language->delete();
        }
    }
};
