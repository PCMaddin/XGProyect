<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    private const OLD_DEFAULT = 'https://xgproyect.org/wp-content/uploads/2019/10/xgp-new-logo-white.png';

    /**
     * The default server logo was an image on xgproyect.org, loaded by every
     * visit of the home page. It is emptied while it is still that default,
     * so the home page shows the logo shipped in public/assets/images. A logo
     * the admin set stays.
     */
    public function up(): void
    {
        Options::where('name', 'game_logo')
            ->whereIn('value', [self::OLD_DEFAULT, str_replace('https://', 'http://', self::OLD_DEFAULT)])
            ->update(['value' => '']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Options::where('name', 'game_logo')->where('value', '')->update(['value' => self::OLD_DEFAULT]);
    }
};
