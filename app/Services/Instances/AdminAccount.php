<?php

declare(strict_types=1);

namespace App\Services\Instances;

use App\Core\Enumerators\UserRanksEnumerator;
use App\Libraries\PlanetLib;
use App\Models\Planets;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The first account of a universe: an administrator with a home planet at
 * 1:1:1. The installer and the universe commands create it the same way.
 */
class AdminAccount
{
    /**
     * @param array{ip?: string|null, agent?: string|null, page?: string|null} $client where it was created from
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function create(string $name, string $email, string $password, array $client = []): User
    {
        DB::beginTransaction();

        try {
            $user = new User([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'home_planet_id' => 0,
                'current_planet' => 0,
                'lastip' => $client['ip'] ?? '',
                'ip_at_reg' => $client['ip'] ?? '',
                'agent' => $client['agent'] ?? '',
                'current_page' => $client['page'] ?? '',
                'register_time' => time(),
                'onlinetime' => time(),
            ]);
            $user->authlevel = UserRanksEnumerator::ADMIN;
            $user->email_verified_at = now();
            $user->save();

            $user->preferences()->create();
            $user->premium()->create();
            $user->research()->create();
            $user->stats()->create();

            (new PlanetLib())->setNewPlanet(1, 1, 1, $user->id, '', true);

            $home = Planets::where('planet_user_id', $user->id)->value('planet_id');
            User::where('id', $user->id)->update([
                'home_planet_id' => $home,
                'current_planet' => $home,
                'galaxy' => 1,
                'system' => 1,
                'planet' => 1,
            ]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $user;
    }
}
