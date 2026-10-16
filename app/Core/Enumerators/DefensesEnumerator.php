<?php

declare(strict_types=1);

namespace App\Core\Enumerators;

abstract class DefensesEnumerator
{
    public const DEFENSE_ROCKET_LAUNCHER = 401;
    public const DEFENSE_LIGHT_LASER = 402;
    public const DEFENSE_HEAVY_LASER = 403;
    public const DEFENSE_GAUSS_CANNON = 404;
    public const DEFENSE_ION_CANNON = 405;
    public const DEFENSE_PLASMA_TURRET = 406;
    public const DEFENSE_SMALL_SHIELD_DOME = 407;
    public const DEFENSE_LARGE_SHIELD_DOME = 408;
    public const DEFENSE_ANTI_BALLISTIC_MISSILE = 502;
    public const DEFENSE_INTERPLANETARY_MISSILE = 503;
}
