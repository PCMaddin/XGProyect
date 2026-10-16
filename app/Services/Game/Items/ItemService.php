<?php

declare(strict_types=1);

namespace App\Services\Game\Items;

use App\Core\Enumerators\PlanetTypesEnumerator;
use App\Libraries\PlanetLib;
use App\Models\Planets;
use App\Services\Game\BuildingQueueService;
use App\Services\Game\ResearchQueueService;
use Illuminate\Support\Facades\DB;

/**
 * The items of the players: inventory, shop, and using them on a planet.
 *
 * - A booster works on the planet it is used on. Another booster of the same
 *   resource and percent extends it; one of another percent can only be
 *   used when it ran out.
 * - Field items work once per planet or moon (not cumulative).
 * - Kraken, detroid and newtron need a running building, shipyard or
 *   research order.
 * - M.O.O.N.S. needs a planet without a moon; it is used up whether the
 *   moon comes or not (outcome() tells which).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class ItemService
{
    /** language key of what the last item did, when there is more to say */
    private ?string $outcome = null;

    public function __construct(
        private ItemCatalog $catalog,
        private BuildingQueueService $buildingQueue,
        private ResearchQueueService $researchQueue,
        private PlanetLib $planetLib,
    ) {
    }

    public function outcome(): ?string
    {
        return $this->outcome;
    }

    /**
     * The items the player owns: key => amount.
     *
     * @return array<string, int>
     */
    public function inventory(int $userId): array
    {
        $inventory = [];

        foreach (DB::table('user_items')->where('user_id', $userId)->where('amount', '>', 0)->get() as $row) {
            if ($this->catalog->get((string) $row->item) !== null) {
                $inventory[(string) $row->item] = (int) $row->amount;
            }
        }

        return $inventory;
    }

    public function add(int $userId, string $key, int $amount = 1): void
    {
        if ($this->catalog->get($key) === null || $amount <= 0) {
            return;
        }

        DB::transaction(function () use ($userId, $key, $amount): void {
            $updated = DB::table('user_items')->where('user_id', $userId)->where('item', $key)->increment('amount', $amount);

            if ($updated === 0) {
                DB::table('user_items')->insert(['user_id' => $userId, 'item' => $key, 'amount' => $amount]);
            }
        });
    }

    /**
     * Buy an item in the shop for dark matter. Error language key, or null.
     */
    public function buy(int $userId, string $key): ?string
    {
        $item = $this->catalog->get($key);

        if ($item === null || $item->price === null) {
            return 'it_unknown_item';
        }

        $paid = DB::table('premium')
            ->where('premium_user_id', $userId)
            ->where('premium_dark_matter', '>=', $item->price)
            ->decrement('premium_dark_matter', $item->price);

        if ($paid !== 1) {
            return 'it_no_enough_dark_matter';
        }

        $this->add($userId, $key);

        return null;
    }

    /**
     * Use an item of the inventory on one of the player's planets or moons.
     * Error language key, or null when it took effect.
     */
    public function use(int $userId, int $planetId, string $key, int $now): ?string
    {
        $item = $this->catalog->get($key);
        $this->outcome = null;

        if ($item === null) {
            return 'it_unknown_item';
        }

        return DB::transaction(function () use ($userId, $planetId, $item, $now): ?string {
            $owned = DB::table('user_items')->where('user_id', $userId)->where('item', $item->key)->lockForUpdate()->value('amount');

            if (!is_numeric($owned) || (int) $owned < 1) {
                return 'it_not_owned';
            }

            $planet = Planets::where('planet_id', $planetId)->where('planet_user_id', $userId)->where('planet_destroyed', 0)->lockForUpdate()->first();

            if ($planet === null) {
                return 'it_unknown_item';
            }

            $error = $this->applyEffect($item, $planet, $userId, $now);

            if ($error === null) {
                DB::table('user_items')->where('user_id', $userId)->where('item', $item->key)->decrement('amount');
            }

            return $error;
        });
    }

    /**
     * The boosters working on the planet at $time: resource => row.
     *
     * @return array<string, object{percent: int, starts_at: int, ends_at: int}>
     */
    public function activeBoosters(int $planetId, int $time): array
    {
        $boosters = [];

        foreach (DB::table('planet_boosters')->where('planet_id', $planetId)->where('ends_at', '>', $time)->get() as $row) {
            $boosters[(string) $row->resource] = (object) [
                'percent' => (int) $row->percent,
                'starts_at' => (int) $row->starts_at,
                'ends_at' => (int) $row->ends_at,
            ];
        }

        return $boosters;
    }

    /**
     * The extra share of the mine production the boosters of the planet
     * give between $from and $to, weighted by how long each worked:
     * resource => share (0.1 for a 10 % booster over the whole time).
     *
     * @return array<string, float>
     */
    public function boosterShares(int $planetId, int $from, int $to): array
    {
        if ($to <= $from) {
            return [];
        }

        $shares = [];

        foreach (DB::table('planet_boosters')->where('planet_id', $planetId)->where('ends_at', '>', $from)->get() as $row) {
            $worked = max(0, min($to, (int) $row->ends_at) - max($from, (int) $row->starts_at));
            $shares[(string) $row->resource] = (int) $row->percent / 100 * $worked / ($to - $from);
        }

        return $shares;
    }

    private function applyEffect(Item $item, Planets $planet, int $userId, int $now): ?string
    {
        return match ($item->type) {
            Item::BOOSTER => $this->applyBooster($item, $planet, $now),
            Item::PLANET_FIELDS, Item::MOON_FIELDS => $this->applyFields($item, $planet),
            Item::KRAKEN => $this->buildingQueue->speedUp($planet, $item->value) ? null : 'it_nothing_building',
            Item::DETROID => $this->speedUpShipyard($planet, $item->value),
            Item::NEWTRON => $this->speedUpResearch($userId, $item->value),
            Item::MOONS => $this->moonShot($item, $planet),
            default => 'it_unknown_item',
        };
    }

    private function applyBooster(Item $item, Planets $planet, int $now): ?string
    {
        if ($planet->planet_type !== PlanetTypesEnumerator::PLANET) {
            return 'it_planet_only';
        }

        $active = $this->activeBoosters($planet->planet_id, $now)[(string) $item->resource] ?? null;

        if ($active !== null && $active->percent !== $item->value) {
            return 'it_other_booster_active';
        }

        DB::table('planet_boosters')->updateOrInsert(
            ['planet_id' => $planet->planet_id, 'resource' => $item->resource],
            $active === null
                ? ['percent' => $item->value, 'starts_at' => $now, 'ends_at' => $now + $item->duration]
                : ['ends_at' => $active->ends_at + $item->duration]
        );

        return null;
    }

    private function applyFields(Item $item, Planets $planet): ?string
    {
        $type = $item->type === Item::MOON_FIELDS ? PlanetTypesEnumerator::MOON : PlanetTypesEnumerator::PLANET;

        if ($planet->planet_type !== $type) {
            return $type === PlanetTypesEnumerator::MOON ? 'it_moon_only' : 'it_planet_only';
        }

        if ($planet->planet_item_fields > 0) {
            return 'it_fields_used';
        }

        $planet->planet_field_max += $item->value;
        $planet->planet_item_fields = $item->value;
        $planet->save();

        return null;
    }

    /**
     * The shipyard counts the time it worked: more of it finishes the order
     * sooner.
     */
    private function speedUpShipyard(Planets $planet, int $seconds): ?string
    {
        if (trim($planet->planet_b_hangar_id, ';') === '') {
            return 'it_nothing_in_shipyard';
        }

        DB::table('planets')->where('planet_id', $planet->planet_id)->increment('planet_b_hangar', $seconds);

        return null;
    }

    /**
     * A moon shot without a fleet: a moon with the item's chance, as big as
     * a moon of a battle with that chance.
     */
    private function moonShot(Item $item, Planets $planet): ?string
    {
        if ($planet->planet_type !== PlanetTypesEnumerator::PLANET) {
            return 'it_planet_only';
        }

        $moon = Planets::where('planet_galaxy', $planet->planet_galaxy)
            ->where('planet_system', $planet->planet_system)
            ->where('planet_planet', $planet->planet_planet)
            ->where('planet_type', PlanetTypesEnumerator::MOON)
            ->exists();

        if ($moon) {
            return 'it_moon_exists';
        }

        $this->outcome = 'it_moons_failed';

        if (mt_rand(0, 99) < $item->value) {
            // the diameter of a battle moon (BattleEngine Events::eventMoon)
            $size = mt_rand(2000 + ($item->value * 100), 6000 + ($item->value * 200));
            $this->planetLib->setNewMoon($planet->planet_galaxy, $planet->planet_system, $planet->planet_planet, $planet->planet_user_id, '', 0, $size);
            $this->outcome = 'it_moons_success';
        }

        return null;
    }

    private function speedUpResearch(int $userId, int $seconds): ?string
    {
        return $this->researchQueue->speedUp($userId, $seconds) ? null : 'it_nothing_researching';
    }
}
