<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    private const OLD_DEFAULTS = [
        'https://www.xgproyect.org/game.php?page=premium',
        'http://www.xgproyect.org/game.php?page=premium',
        'https://xgproyect.org/game.php?page=premium',
        'http://xgproyect.org/game.php?page=premium',
    ];

    /**
     * The "get dark matter" button pointed to the original project's site by
     * default, where nothing can be bought for this server. The old default
     * is emptied, which hides the button; a shop the admin set stays.
     */
    public function up(): void
    {
        Options::where('name', 'premium_url')->whereIn('value', self::OLD_DEFAULTS)->update(['value' => '']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Options::where('name', 'premium_url')->where('value', '')->update(['value' => self::OLD_DEFAULTS[0]]);
    }
};
