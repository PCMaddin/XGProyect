<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\GameObjects\GameObjectInterface;
use App\Core\GameObjects\GameObjectRegistry;
use App\Models\Fleets;
use App\Models\Planets;
use App\Models\Preferences;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Vacation mode: production stops while it is on; it can be switched on
 * only while no fleet flies and nothing is built, and off after two days.
 */
class VacationModeService
{
    public function __construct(
        private GameObjectRegistry $registry,
    ) {
    }

    public function isActive(Preferences $preferences): bool
    {
        return (int) $preferences->preference_vacation_mode > 0;
    }

    public function canBeRemoved(Preferences $preferences): bool
    {
        return ((int) $preferences->preference_vacation_mode + ONE_DAY * 2) < time();
    }

    /**
     * A fleet is underway, or a building, research or shipyard order runs.
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function isEmpireActive(User $user): bool
    {
        return Fleets::where('fleet_owner', $user->id)->exists() ||
            Planets::where('planet_user_id', $user->id)
                ->where(function ($query): void {
                    $query->where('planet_b_building', '<>', 0)
                        ->orWhere('planet_b_tech', '<>', 0)
                        ->orWhere('planet_b_hangar', '<>', 0);
                })
                ->exists();
    }

    /**
     * End the vacation mode when allowed, or start it when the empire is idle.
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function toggle(User $user, Preferences $preferences): void
    {
        if ($this->isActive($preferences)) {
            if ($this->canBeRemoved($preferences)) {
                DB::transaction(function () use ($user, $preferences): void {
                    $preferences->update(['preference_vacation_mode' => null]);
                    Planets::where('planet_user_id', $user->id)
                        ->update($this->producerPercents(10) + ['planet_last_update' => time()]);
                });
            }

            return;
        }

        if ($this->isEmpireActive($user)) {
            return;
        }

        DB::transaction(function () use ($user, $preferences): void {
            $preferences->update(['preference_vacation_mode' => time()]);
            Planets::where('planet_user_id', $user->id)->update($this->producerPercents(0));
        });
    }

    /**
     * The production percentage columns of all producers, set to $level (0-10).
     *
     * @return array<string, int>
     */
    public function producerPercents(int $level): array
    {
        return $this->registry->producers()
            ->mapWithKeys(fn (GameObjectInterface $object) => [
                'planet_' . $object->getName() . '_percent' => $level,
            ])
            ->all();
    }
}
