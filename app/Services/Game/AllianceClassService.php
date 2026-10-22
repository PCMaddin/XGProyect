<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Enums\AllianceClass;
use Illuminate\Support\Facades\DB;

/**
 * The alliance classes of OGame 7: an alliance administrator chooses the
 * class for all members. The first choice is free once the alliance has
 * existed for 14 days; before that, and for a change, the administrator
 * pays dark matter (class_change_price, the price of the class change
 * token).
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

    /** the first choice is free after this long */
    public const FREE_AFTER = 14 * 86400;

    public function changePrice(): int
    {
        return $this->playerClasses->changePrice();
    }

    /**
     * When the first choice of the alliance becomes free.
     */
    public function freeFrom(int $allianceId): int
    {
        $founded = DB::table('alliance')->where('alliance_id', $allianceId)->value('alliance_register_time');

        return (is_numeric($founded) ? (int) $founded : 0) + self::FREE_AFTER;
    }

    /**
     * What choosing a class costs now: nothing for the first choice of an
     * alliance that has existed for 14 days, else the change price.
     */
    public function priceOf(int $allianceId, int $now): int
    {
        if ($this->classOf($allianceId) === AllianceClass::None && $now >= $this->freeFrom($allianceId)) {
            return 0;
        }

        return $this->changePrice();
    }

    /**
     * Choose the class of the alliance; $payerId pays a change. Error
     * language key, or null.
     */
    public function choose(int $allianceId, int $payerId, AllianceClass $class, ?int $now = null): ?string
    {
        if ($class === AllianceClass::None) {
            return 'al_class_unknown';
        }

        $now ??= time();

        return DB::transaction(function () use ($allianceId, $payerId, $class, $now): ?string {
            $current = AllianceClass::fromInt(DB::table('alliance')->where('alliance_id', $allianceId)->lockForUpdate()->value('alliance_class'));

            if ($current === $class) {
                return 'al_class_already_chosen';
            }

            if ($current !== AllianceClass::None || $now < $this->freeFrom($allianceId)) {
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
