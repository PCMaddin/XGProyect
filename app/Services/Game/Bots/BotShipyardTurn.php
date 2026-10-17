<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Core\Concerns\PreparesSql;
use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Models\Planets;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Support\Concerns\ReadsMixedValues;
use App\Support\FleetShips;
use Illuminate\Support\Facades\DB;

/**
 * A bot planet's shipyard in one turn: missiles first while a target is in
 * range ({@see BotCoordinator}), then the orders {@see BotShipyard} chooses,
 * queued and paid like the shipyard page does.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class BotShipyardTurn
{
    use PreparesSql;
    use ReadsMixedValues;

    /** the fleet and defences a bot keeps, against its buildings and research */
    private const MILITARY_SHARE = 1 / 3;

    public function __construct(
        private BotShipyard $shipyard,
        private BotFleetPlanner $planner,
        private BotCoordinator $coordinator,
        private DevelopmentsService $developments,
    ) {
    }

    /**
     * Ships and defences, like the shipyard page orders them, while the
     * shipyard is idle and not being upgraded.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet the planet's row with its ships and defences
     * @param array<int, int>      $levels
     * @param array<string, float> $reserve
     */
    public function act(array $user, array $planet, array $levels, array $reserve, int $now): void
    {
        $model = $this->idleShipyard($user, $planet, $levels);

        if ($model === null) {
            return;
        }

        $stock = ['metal' => $model->planet_metal, 'crystal' => $model->planet_crystal, 'deuterium' => $model->planet_deuterium];
        // missiles while a target is in range, then a few orders at once,
        // like a player filling the shipyard
        $missiles = $this->coordinator->shipyardOrder($user, $levels, $planet, $now);
        $missileOrders = $missiles === null ? [] : [$missiles];
        $paid = $this->price($missileOrders);

        $orders = [...$missileOrders, ...$this->shipyard->orders(
            $levels,
            $this->withFlying($this->shipyard->shipsOf($planet), $planet),
            $model->planet_id === self::asInt($user['home_planet_id'] ?? 0),
            $this->planner->wantsColony($user),
            $this->planner->wasRaided($user, $now),
            ['metal' => $stock['metal'] - $paid['metal'], 'crystal' => $stock['crystal'] - $paid['crystal'], 'deuterium' => $stock['deuterium'] - $paid['deuterium']],
            $reserve,
            $this->militaryLags(self::asInt($user['id'] ?? 0))
        )];

        if ($orders !== []) {
            $this->queue($model->planet_id, $orders);
        }
    }

    /**
     * The planet when its shipyard may take orders: not saving for the
     * missile silo, nothing queued, the shipyard and factories not being
     * upgraded.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @param array<int, int>      $levels
     */
    private function idleShipyard(array $user, array $planet, array $levels): ?Planets
    {
        $planetId = self::asInt($planet['planet_id'] ?? 0);

        // a home planet saving for its missile silo does not spend on ships either
        if ($planet === [] || ($planetId === self::asInt($user['home_planet_id'] ?? 0) && $this->coordinator->savesForSilo($user, $levels))) {
            return null;
        }

        $model = Planets::find($planetId);

        if ($model === null || !in_array($model->planet_b_hangar_id, ['', '0', null], true) ||
            $model->buildingQueue()->whereIn('building_id', [Buildings::BUILDING_ROBOT_FACTORY, Buildings::BUILDING_NANO_FACTORY, Buildings::BUILDING_HANGAR])->exists()) {
            return null;
        }

        return $model;
    }

    /**
     * @param list<array{0: int, 1: int}> $orders
     *
     * @return array{metal: float, crystal: float, deuterium: float}
     */
    private function price(array $orders): array
    {
        $paid = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];

        foreach ($orders as [$unit, $amount]) {
            foreach ($this->developments->developmentPrice($unit, 0, false) as $resource => $price) {
                if (isset($paid[$resource])) {
                    $paid[$resource] += $price * $amount;
                }
            }
        }

        return $paid;
    }

    /**
     * Queue the orders and pay them, like the shipyard page.
     *
     * @param list<array{0: int, 1: int}> $orders
     */
    private function queue(int $planetId, array $orders): void
    {
        $paid = $this->price($orders);
        $queue = implode('', array_map(static fn (array $order): string => $order[0] . ',' . $order[1] . ';', $orders));

        DB::update(
            $this->prepareSql(
                'UPDATE `' . PLANETS . '` SET
                    `planet_b_hangar_id` = CONCAT(COALESCE(`planet_b_hangar_id`, \'\'), ?),
                    `planet_metal` = `planet_metal` - ?,
                    `planet_crystal` = `planet_crystal` - ?,
                    `planet_deuterium` = `planet_deuterium` - ?
                WHERE `planet_id` = ?;'
            ),
            [$queue, $paid['metal'], $paid['crystal'], $paid['deuterium'], $planetId]
        );
    }

    /**
     * Whether the fleet and defences are worth less than a third of the
     * buildings and research (by the statistics' points): then they come
     * before the next building, as a player builds up a fleet.
     */
    private function militaryLags(int $userId): bool
    {
        $points = DB::table('users_statistics')->where('user_statistic_user_id', $userId)->first([
            'user_statistic_buildings_points', 'user_statistic_technology_points', 'user_statistic_ships_points', 'user_statistic_defenses_points',
        ]);

        if ($points === null) {
            return false;
        }

        $economy = self::asFloat($points->user_statistic_buildings_points) + self::asFloat($points->user_statistic_technology_points);

        return self::asFloat($points->user_statistic_ships_points) + self::asFloat($points->user_statistic_defenses_points) < self::MILITARY_SHARE * $economy;
    }

    /**
     * The planet's ships plus those flying from it, so ships out on a
     * mission (probes spying, cargo ships on an expedition) are not built
     * again.
     *
     * @param array<int, int>      $units
     * @param array<string, mixed> $planet
     *
     * @return array<int, int>
     */
    private function withFlying(array $units, array $planet): array
    {
        $fleets = DB::table('fleets')
            ->where('fleet_owner', self::asInt($planet['planet_user_id'] ?? 0))
            ->where('fleet_start_galaxy', self::asInt($planet['planet_galaxy'] ?? 0))
            ->where('fleet_start_system', self::asInt($planet['planet_system'] ?? 0))
            ->where('fleet_start_planet', self::asInt($planet['planet_planet'] ?? 0))
            ->where('fleet_start_type', self::asInt($planet['planet_type'] ?? 0))
            ->pluck('fleet_array');

        foreach ($fleets as $fleet) {
            foreach (FleetShips::decode(self::asString($fleet)) as $ship => $amount) {
                $units[$ship] = ($units[$ship] ?? 0) + $amount;
            }
        }

        return $units;
    }
}
