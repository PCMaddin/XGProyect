<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\Enumerators\PlanetTypesEnumerator;
use App\Enums\PlayerClass;
use App\Libraries\Users;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * The player classes of OGame 7: which class a player has, and choosing
 * one. The first choice is free, a change costs dark matter
 * (class_change_price, 500.000 as for the original's class change token).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PlayerClassService
{
    /** @var array<int, PlayerClass> */
    private array $classes = [];

    public function __construct(private SettingsService $settings)
    {
    }

    public function classOf(int $userId): PlayerClass
    {
        return $this->classes[$userId] ??= PlayerClass::fromInt(
            DB::table('users')->where('id', $userId)->value('player_class')
        );
    }

    /**
     * The class of the logged-in player.
     */
    public function current(): PlayerClass
    {
        $user = Users::getInstance()->getUserData();

        return PlayerClass::fromInt($user['player_class'] ?? 0);
    }

    public function changePrice(): int
    {
        return max(0, $this->settings->getInt('class_change_price'));
    }

    /**
     * Choose a class. Error language key, or null.
     */
    public function choose(int $userId, PlayerClass $class): ?string
    {
        if ($class === PlayerClass::None) {
            return 'cc_unknown_class';
        }

        return DB::transaction(function () use ($userId, $class): ?string {
            $current = PlayerClass::fromInt(DB::table('users')->where('id', $userId)->lockForUpdate()->value('player_class'));

            if ($current === $class) {
                return 'cc_already_chosen';
            }

            if ($current !== PlayerClass::None) {
                $price = $this->changePrice();
                $paid = DB::table('premium')->where('premium_user_id', $userId)->where('premium_dark_matter', '>=', $price)->decrement('premium_dark_matter', $price);

                if ($paid !== 1) {
                    return 'cc_no_enough_dark_matter';
                }
            }

            DB::table('users')->where('id', $userId)->update(['player_class' => $class->value]);
            $this->adjustMoonFields($userId, $class->moonFieldBonus() - $current->moonFieldBonus());
            $this->classes[$userId] = $class;

            return null;
        });
    }

    /**
     * Generals' moons have more fields: add or take them on a change.
     */
    private function adjustMoonFields(int $userId, int $fields): void
    {
        if ($fields === 0) {
            return;
        }

        DB::table('planets')
            ->where('planet_user_id', $userId)
            ->where('planet_type', PlanetTypesEnumerator::MOON)
            ->update(['planet_field_max' => DB::raw('CASE WHEN planet_field_max + ' . $fields . ' < 1 THEN 1 ELSE planet_field_max + ' . $fields . ' END')]);
    }
}
