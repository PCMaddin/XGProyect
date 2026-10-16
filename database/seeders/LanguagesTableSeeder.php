<?php

namespace Database\Seeders;

use App\Models\Languages;
use Illuminate\Database\Seeder;

class LanguagesTableSeeder extends Seeder
{
    public function run()
    {
        $languages = [
            ['name' => 'Español', 'code' => 'es'],
            ['name' => 'English', 'code' => 'en'],
            ['name' => 'Deutsch', 'code' => 'de'],
            ['name' => 'Français', 'code' => 'fr'],
            ['name' => 'Português (Brasil)', 'code' => 'pt_BR'],
            ['name' => 'Nederlands', 'code' => 'nl'],
            ['name' => 'Italiano', 'code' => 'it'],
            ['name' => 'Polski', 'code' => 'pl'],
            ['name' => '日本語', 'code' => 'ja'],
        ];

        Languages::insert($languages);
    }
}
