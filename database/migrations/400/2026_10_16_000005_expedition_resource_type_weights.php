<?php

declare(strict_types=1);

use App\Models\Options;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /** @var array<string, array{0: string, 1: string}> option => [former default, original game] */
    private array $weights = [
        'expedition_resource_type_metal_weight' => ['6850', '5000'],
        'expedition_resource_type_crystal_weight' => ['2400', '3333'],
        'expedition_resource_type_deuterium_weight' => ['750', '1667'],
    ];

    /**
     * Expeditions find metal, crystal and deuterium 3:2:1 as in the original
     * game; only weights an admin did not change are updated.
     */
    public function up(): void
    {
        foreach ($this->weights as $name => [$former, $original]) {
            Options::where('name', $name)->where('value', $former)->update(['value' => $original]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->weights as $name => [$former, $original]) {
            Options::where('name', $name)->where('value', $original)->update(['value' => $former]);
        }
    }
};
