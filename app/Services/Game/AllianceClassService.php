<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Enums\AllianceClass;
use Illuminate\Support\Facades\DB;

/**
 * The alliance classes of OGame 7: an alliance administrator chooses the
 * class for all members. The first choice is free, a change costs the
 * administrator dark matter (class_change_price, as a player class change).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class AllianceClassService
{
    public function __construct(private PlayerClassService $playerClasses)
    {
    }

    public function classOf(int $allianceId): AllianceClass
    {
        return AllianceClass::fromInt(DB::table('alliance')->where('alliance_id', $allianceId)->value('alliance_class'));
    }

    public function changePrice(): int
    {
        return $this->playerClasses->changePrice();
    }

    /**
     * Choose the class of the alliance; $payerId pays a change. Error
     * language key, or null.
     */
    public function choose(int $allianceId, int $payerId, AllianceClass $class): ?string
    {
        if ($class === AllianceClass::None) {
            return 'al_class_unknown';
        }

        return DB::transaction(function () use ($allianceId, $payerId, $class): ?string {
            $current = AllianceClass::fromInt(DB::table('alliance')->where('alliance_id', $allianceId)->lockForUpdate()->value('alliance_class'));

            if ($current === $class) {
                return 'al_class_already_chosen';
            }

            if ($current !== AllianceClass::None) {
                $price = $this->changePrice();
                $paid = DB::table('premium')->where('premium_user_id', $payerId)->where('premium_dark_matter', '>=', $price)->decrement('premium_dark_matter', $price);

                if ($paid !== 1) {
                    return 'al_class_no_enough_dark_matter';
                }
            }

            DB::table('alliance')->where('alliance_id', $allianceId)->update(['alliance_class' => $class->value]);

            return null;
        });
    }
}
