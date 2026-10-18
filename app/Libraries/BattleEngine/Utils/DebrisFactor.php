<?php

declare(strict_types=1);

namespace App\Libraries\BattleEngine\Utils;

use App\Services\SettingsService;
use Throwable;

/**
 * The share of lost ships or defenses that becomes debris: the options
 * fleet_cdr and defs_cdr of the universe the battle is fought in.
 *
 * They were constants, defined once per process from the first universe's
 * options: a scheduler that runs the fleets of every universe one after the
 * other used that universe's shares for all of them. Without options (the
 * battle engine on its own) the constants' defaults apply.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
final class DebrisFactor
{
    private const OPTIONS = ['ship' => 'fleet_cdr', 'defense' => 'defs_cdr'];

    public static function share(string $role): float
    {
        $option = self::OPTIONS[strtolower($role)] ?? null;

        if ($option !== null) {
            try {
                $settings = app(SettingsService::class)->all();

                if (isset($settings[$option]) && is_numeric($settings[$option]->value)) {
                    return (float) $settings[$option]->value / 100;
                }
            } catch (Throwable) {
                // no database: the defaults below
            }
        }

        $default = defined(strtoupper($role) . '_DEBRIS_FACTOR') ? constant(strtoupper($role) . '_DEBRIS_FACTOR') : 0;

        return is_numeric($default) ? (float) $default : 0.0;
    }
}
