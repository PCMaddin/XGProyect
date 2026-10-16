<?php

declare(strict_types=1);

namespace Xgp\App\Core\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Resolves the {xgp_prefix} placeholder of the legacy table names.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
trait PreparesLegacySql
{
    private function prepareSql(string $sql): string
    {
        return strtr($sql, ['{xgp_prefix}' => DB::getTablePrefix()]);
    }
}
