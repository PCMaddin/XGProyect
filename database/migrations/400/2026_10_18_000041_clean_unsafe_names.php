<?php

declare(strict_types=1);

use App\Support\Html;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Names with characters that end an HTML text, attribute or script
     * string. The legacy pages put names into HTML without escaping them
     * everywhere; players could not enter such names, admins could, and
     * older versions let them through. Cleaned once here, refused since.
     *
     * @var array<string, array{0: string, 1: list<string>}> table => key, columns
     */
    private const COLUMNS = [
        'users' => ['id', ['name']],
        'planets' => ['planet_id', ['planet_name']],
        'alliance' => ['alliance_id', ['alliance_name', 'alliance_tag']],
        'acs' => ['acs_id', ['acs_name']],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => [$key, $columns]) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                $this->cleanColumn($table, $key, $column);
            }
        }
    }

    private function cleanColumn(string $table, string $key, string $column): void
    {
        $rows = DB::table($table)
            ->where(function ($query) use ($column): void {
                foreach (['<', '>', '"', "'", '&', '\\', '`'] as $character) {
                    $query->orWhere($column, 'like', '%' . addcslashes($character, '%_\\') . '%');
                }
            })
            ->get([$key, $column]);

        foreach ($rows as $row) {
            $values = (array) $row;
            $clean = Html::cleanName(is_string($values[$column]) ? $values[$column] : '');
            DB::table($table)->where($key, $values[$key])->update([$column => $clean !== '' ? $clean : '-']);
        }
    }
};
