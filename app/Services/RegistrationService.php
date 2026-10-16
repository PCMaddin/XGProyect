<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\PlanetLib;
use App\Models\Planets;
use App\Models\User;
use App\Services\Game\PlanetService;
use Illuminate\Support\Facades\DB;
use Throwable;
use App\Core\Enumerators\UserRanksEnumerator;

/**
 * Creates a new player: the account with its preferences, premium,
 * research and statistics rows, and the home planet.
 */
class RegistrationService
{
    public function __construct(
        private PlanetService $planetService,
    ) {
    }

    /**
     * The new player, or null when it could not be created; nothing is kept
     * then.
     *
     * Bugfix: after a failure the rolled-back user was used further: the
     * welcome message and email went to a player that did not exist, and
     * the error was swallowed. Now it is reported.
     *
     * @param array<string, mixed> $attributes the user columns from the form and request
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function register(array $attributes): ?User
    {
        $coords = $this->planetService->calculateNewPlanetPosition();

        try {
            return DB::transaction(fn (): User => $this->createPlayer($attributes, $coords));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param array<string, mixed>                           $attributes
     * @param array{galaxy: int, system: int, planet: int} $coords
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    private function createPlayer(array $attributes, array $coords): User
    {
        $user = User::create(array_merge($attributes, [
            'home_planet_id' => 0,
            'current_planet' => 0,
            'register_time' => time(),
            'onlinetime' => time(),
            'authlevel' => UserRanksEnumerator::PLAYER,
        ]));

        $user->preferences()->create();
        $user->premium()->create();
        $user->research()->create();
        $user->stats()->create();

        (new PlanetLib())->setNewPlanet($coords['galaxy'], $coords['system'], $coords['planet'], $user->id, '', true);

        $homePlanetId = Planets::where('planet_user_id', $user->id)->value('planet_id');

        User::where('id', $user->id)->update([
            'home_planet_id' => $homePlanetId,
            'current_planet' => $homePlanetId,
            'galaxy' => $coords['galaxy'],
            'system' => $coords['system'],
            'planet' => $coords['planet'],
        ]);

        return $user;
    }
}
