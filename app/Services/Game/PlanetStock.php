<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Exceptions\NoLongerOnPlanet;
use App\Models\Planets;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Takes ships and resources from a planet in one statement, and only if
 * all of it is still there. A check before and an unconditional "minus"
 * after let two requests at the same moment take the same ships twice:
 * two fleets flew, the planet went below zero.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PlanetStock
{
    private const RESOURCES = ['metal', 'crystal', 'deuterium'];

    /**
     * @param array<string, int>       $ships     ship column => amount
     * @param array<string, int|float> $resources metal, crystal, deuterium => amount
     *
     * @throws NoLongerOnPlanet
     */
    public function take(int $planetId, array $ships, array $resources = []): void
    {
        $set = [];
        $where = [];
        $setBindings = [];
        $whereBindings = [];

        foreach ($ships as $column => $amount) {
            if ($amount <= 0) {
                continue;
            }

            self::assertColumn($column, 'ship_');
            $set[] = 's.`' . $column . '` = s.`' . $column . '` - ?';
            $where[] = 's.`' . $column . '` >= ?';
            $setBindings[] = $amount;
            $whereBindings[] = $amount;
        }

        foreach (self::RESOURCES as $resource) {
            $amount = $resources[$resource] ?? 0;

            if ($amount <= 0) {
                continue;
            }

            $set[] = 'p.`planet_' . $resource . '` = p.`planet_' . $resource . '` - ?';
            $where[] = 'p.`planet_' . $resource . '` >= ?';
            $setBindings[] = $amount;
            $whereBindings[] = $amount;
        }

        if ($set === []) {
            return;
        }

        $taken = DB::update(
            'UPDATE `' . DB::getTablePrefix() . 'planets` AS p
            INNER JOIN `' . DB::getTablePrefix() . 'ships` AS s ON s.`ship_planet_id` = p.`planet_id`
            SET ' . implode(', ', $set) . '
            WHERE p.`planet_id` = ? AND ' . implode(' AND ', $where),
            [...$setBindings, $planetId, ...$whereBindings]
        );

        if ($taken === 0) {
            throw new NoLongerOnPlanet('The ships or resources are no longer on planet ' . $planetId);
        }
    }

    /**
     * Pays a price from the planet if it is still there, and brings the
     * model up to date without marking it changed: a later save() of the
     * model must not write back resources read before (that undid what
     * another request took meanwhile, e.g. a fleet's cargo).
     *
     * @param array<string, int|float> $price metal, crystal, deuterium => amount
     *
     * @throws NoLongerOnPlanet
     */
    public function spend(Planets $planet, array $price): void
    {
        $query = Planets::whereKey($planet->planet_id);
        $changes = [];

        foreach (self::RESOURCES as $resource) {
            $amount = (float) ($price[$resource] ?? 0);

            if ($amount > 0) {
                $query->where('planet_' . $resource, '>=', $amount);
                $changes['planet_' . $resource] = DB::raw('`planet_' . $resource . '` - ' . $amount);
            }
        }

        if ($changes !== [] && $query->toBase()->update($changes) !== 1) {
            throw new NoLongerOnPlanet('The price is no longer on planet ' . $planet->planet_id);
        }

        $this->sync($planet);
    }

    /**
     * Gives resources back to the planet (added, never set).
     *
     * @param array<string, int|float> $amounts metal, crystal, deuterium => amount
     */
    public function refund(Planets $planet, array $amounts): void
    {
        $changes = [];

        foreach (self::RESOURCES as $resource) {
            $amount = (float) ($amounts[$resource] ?? 0);

            if ($amount > 0) {
                $changes['planet_' . $resource] = DB::raw('`planet_' . $resource . '` + ' . $amount);
            }
        }

        if ($changes !== []) {
            Planets::whereKey($planet->planet_id)->toBase()->update($changes);
        }

        $this->sync($planet);
    }

    private function sync(Planets $planet): void
    {
        $fresh = Planets::whereKey($planet->planet_id)->toBase()->first(['planet_metal', 'planet_crystal', 'planet_deuterium']);

        foreach ((array) $fresh as $column => $value) {
            $planet->setAttribute($column, $value);
            $planet->syncOriginalAttribute($column);
        }
    }

    private static function assertColumn(string $column, string $prefix): void
    {
        if (preg_match('/^' . $prefix . '[a-z_]+$/', $column) !== 1) {
            throw new InvalidArgumentException('Not a column: ' . $column);
        }
    }
}
