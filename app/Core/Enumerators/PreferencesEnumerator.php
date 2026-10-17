<?php

declare(strict_types=1);

namespace App\Core\Enumerators;

abstract class PreferencesEnumerator
{
    public const ORDER = [
        'emergence' => 0,
        'coordinates' => 1,
        'alphabet' => 2,
        'size' => 3,
        'used_fields' => 4,
    ];

    public const SEQUENCE = [
        'up' => 0,
        'down' => 1,
    ];
}
