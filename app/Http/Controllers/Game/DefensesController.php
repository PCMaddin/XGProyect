<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use Xgp\App\Core\Enumerators\DefensesEnumerator as Defenses;

/**
 * Defences and missiles construction. Reuses the shipyard build logic with the
 * defence item set.
 */
class DefensesController extends ShipyardController
{
    protected string $page = 'defenses';

    protected string $langFile = 'defenses';

    /** @var array<int, int> */
    protected array $allowedStructures = [
        Defenses::DEFENSE_ROCKET_LAUNCHER,
        Defenses::DEFENSE_LIGHT_LASER,
        Defenses::DEFENSE_HEAVY_LASER,
        Defenses::DEFENSE_GAUSS_CANNON,
        Defenses::DEFENSE_ION_CANNON,
        Defenses::DEFENSE_PLASMA_TURRET,
        Defenses::DEFENSE_SMALL_SHIELD_DOME,
        Defenses::DEFENSE_LARGE_SHIELD_DOME,
        Defenses::DEFENSE_ANTI_BALLISTIC_MISSILE,
        Defenses::DEFENSE_INTERPLANETARY_MISSILE,
    ];

    /** @var array<int, int> */
    protected array $missiles = [
        Defenses::DEFENSE_ANTI_BALLISTIC_MISSILE => 0,
        Defenses::DEFENSE_INTERPLANETARY_MISSILE => 0,
    ];
}
