<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /** @var array<string, string> */
    private array $options = [
        'merchant_scrap_base_rate' => '35',
        'merchant_scrap_max_rate' => '75',
        'merchant_scrap_bargain_price' => '2000',
    ];

    /**
     * The scrap merchant: his settings, and the rate and number of bargains
     * of a player, kept until the next sale.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('premium', 'premium_scrap_rate')) {
            Schema::table('premium', function (Blueprint $table): void {
                $table->unsignedTinyInteger('premium_scrap_rate')->nullable();
            });
        }

        if (!Schema::hasColumn('premium', 'premium_scrap_bargains')) {
            Schema::table('premium', function (Blueprint $table): void {
                $table->unsignedTinyInteger('premium_scrap_bargains')->default(0);
            });
        }

        foreach ($this->options as $name => $value) {
            Options::firstOrCreate(['name' => $name], ['value' => $value, 'type' => 'int']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['premium_scrap_rate', 'premium_scrap_bargains'] as $column) {
            if (Schema::hasColumn('premium', $column)) {
                Schema::table('premium', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        Options::whereIn('name', array_keys($this->options))->delete();
    }
};
