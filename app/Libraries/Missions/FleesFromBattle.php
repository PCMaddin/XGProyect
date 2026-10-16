<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Services\Game\HonorService;
use App\Libraries\BattleEngine\Models\PlayerGroup;
use App\Libraries\Functions;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\PlayerClassService;
use App\Services\SettingsService;
use App\Support\OfficerBonuses;
use Illuminate\Support\Facades\DB;

/**
 * The fleet escape of the defender before a battle (see FleetEscape).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
trait FleesFromBattle
{
    /** the report line on the defender's fleet that fled */
    private string $escapeLine = '';

    /**
     * The fleet escape: the defender's ships on the planet flee from an
     * attacker at least 5 times as strong (3 times with an admiral), if the
     * player allows it, is active, is within the noob protection limits and
     * has the deuterium (1.5 times a flight to the next position).
     *
     * @param array<string, mixed> $targetUser
     * @param array<string, mixed> $targetPlanet
     *
     * @return array<int, int> the ships that fled
     */
    private function fleeFrom(PlayerGroup $attackers, PlayerGroup $defenders, array $targetUser, array &$targetPlanet): array
    {
        if (!$this->mayFlee($targetUser)) {
            return [];
        }

        $escape = app(FleetEscape::class);
        $planetShips = $this->planetShips($targetPlanet);
        $fleeing = $escape->fleeing($planetShips);
        $defenderShips = self::groupShips($defenders);

        foreach ($planetShips as $id => $count) {
            $defenderShips[$id] = ($defenderShips[$id] ?? 0) + $count;
        }

        $admiral = OfficerBonuses::fromRow($targetUser, time())->admiral;

        if ($fleeing === [] || !$escape->overwhelms($escape->strength(self::groupShips($attackers)), $escape->strength($defenderShips), $admiral)) {
            return [];
        }

        $fuel = $this->escapeFuel($fleeing, $targetUser, $targetPlanet);

        if (self::num($targetPlanet['planet_deuterium'] ?? 0) < $fuel) {
            return [];
        }

        DB::table('planets')->where('planet_id', self::int($targetPlanet['planet_id']))->decrement('planet_deuterium', $fuel);
        $targetPlanet['planet_deuterium'] = self::num($targetPlanet['planet_deuterium']) - $fuel;
        $this->escapeLine = sprintf((string) __('game/attack.at_fleet_fled'), $this->formatService->prettyNumber((int) array_sum($fleeing)));

        return $fleeing;
    }

    /**
     * The player allows the escape, is active and within the noob
     * protection limits.
     *
     * @param array<string, mixed> $targetUser
     */
    private function mayFlee(array $targetUser): bool
    {
        return self::int($targetUser['preference_fleet_escape'] ?? 0) === 1 &&
            !$this->defenderInactive &&
            $this->honourFight !== HonorService::HONOURABLE &&
            $this->withinNoobProtection(self::int($targetUser['id']));
    }

    /**
     * @param array<string, mixed> $targetPlanet
     *
     * @return array<int, int|float>
     */
    private function planetShips(array $targetPlanet): array
    {
        $ships = [];

        foreach ($this->resource as $id => $column) {
            if ($id > 200 && $id < 300 && self::num($targetPlanet[$column] ?? 0) > 0) {
                $ships[$id] = self::num($targetPlanet[$column]);
            }
        }

        return $ships;
    }

    /**
     * @return array<int, int|float> ship id => count over all players and fleets
     */
    private static function groupShips(PlayerGroup $group): array
    {
        $ships = [];

        foreach ($group->getIterator() as $player) {
            foreach ($player->getIterator() as $fleet) {
                foreach ($fleet->getIterator() as $id => $shipType) {
                    $ships[(int) $id] = ($ships[(int) $id] ?? 0) + $shipType->getCount();
                }
            }
        }

        return $ships;
    }

    private function withinNoobProtection(int $userId): bool
    {
        $settings = app(SettingsService::class);

        if (!$settings->getBool('noobprotection')) {
            return false;
        }

        $limit = $settings->getInt('noobprotectiontime');
        $points = DB::table('users_statistics')->where('user_statistic_user_id', $userId)->value('user_statistic_total_points');

        return $limit <= 0 || (is_numeric($points) ? (float) $points : 0.0) < $limit;
    }

    /**
     * 1.5 times the deuterium of a flight to the next position.
     *
     * @param array<int, int>      $ships
     * @param array<string, mixed> $targetUser
     * @param array<string, mixed> $targetPlanet
     */
    private function escapeFuel(array $ships, array $targetUser, array $targetPlanet): int
    {
        $fleets = app(FleetsService::class);
        $bonuses = PlayerClassService::bonusesFromRow($targetUser);
        $drives = [self::int($targetUser['research_combustion_drive'] ?? 0), self::int($targetUser['research_impulse_drive'] ?? 0), self::int($targetUser['research_hyperspace_drive'] ?? 0)];
        $planet = self::int($targetPlanet['planet_planet']);
        $distance = $fleets->targetDistance(0, 0, 0, 0, $planet, $planet === 1 ? 2 : $planet - 1);
        $speeds = $fleets->fleetMaxSpeed($ships, ...[...$drives, $bonuses]);
        $speedFactor = Functions::fleetSpeedFactor();
        $duration = (int) $fleets->missionDuration(10, (float) min($speeds ?: [1]), $distance, $speedFactor);

        return (int) ceil(FleetEscape::FUEL_FACTOR * $fleets->fleetConsumption($ships, $speedFactor, $duration, $distance, ...[...$drives, $bonuses]));
    }
}
