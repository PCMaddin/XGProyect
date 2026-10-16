<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\Enumerators\PlanetTypesEnumerator;
use App\Core\Enumerators\ShipsEnumerator;
use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Ship;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * Wreck fields and their repair in the space dock (OGame 7, owiki
 * "Raumdock"):
 *
 * - a battle over a planet or its moon leaves a wreck field at the planet
 *   when at least 150.000 units and 5 % of the defender's ships there were
 *   destroyed; what did not become debris (70 % in a 30 % universe) lies in
 *   it. Attacking generals get one at their start planet the same way,
 *   which can be repaired once the fleet is back;
 * - another battle adds to the field and gives it 3 more days; it burns up
 *   when no repair was started within 3 days;
 * - the space dock repairs 45 % of the wrecks at level 1 up to 56 % from
 *   level 14 (31,5 % to 39,2 % of the destroyed ships in a 30 % universe),
 *   in the time the shipyard would take, at least 30 minutes, at most 12
 *   hours, for free;
 * - the repaired ships are put back into service by the player, or on
 *   their own 3 days after the repair.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class WreckFieldService
{
    public const MIN_UNITS = 150000;
    public const MIN_SHARE = 0.05;
    public const LIFETIME = 3 * 86400;
    public const MIN_REPAIR_TIME = 1800;
    public const MAX_REPAIR_TIME = 12 * 3600;

    /** the share of the wrecks the space dock repairs, by level */
    private const REPAIR_SHARES = [
        1 => 0.45, 2 => 0.48, 3 => 0.49, 4 => 0.50, 5 => 0.51, 6 => 0.52, 7 => 0.53, 8 => 0.53,
        9 => 0.54, 10 => 0.54, 11 => 0.55, 12 => 0.55, 13 => 0.55, 14 => 0.56,
    ];

    /** ships that leave no wrecks */
    private const NO_WRECKS = [ShipsEnumerator::SHIP_SOLAR_SATELLITE, ShipsEnumerator::SHIP_CRAWLER];

    public function __construct(
        private GameObjectRegistry $registry,
        private DevelopmentsService $developments,
        private SettingsService $settings,
    ) {
    }

    public function repairShare(int $dockLevel): float
    {
        if ($dockLevel < 1) {
            return 0.0;
        }

        return self::REPAIR_SHARES[min($dockLevel, 14)];
    }

    /**
     * The units (metal + crystal + deuterium) of the ships.
     *
     * @param array<int, int> $ships
     */
    public function units(array $ships): int
    {
        $units = 0;

        foreach ($ships as $id => $amount) {
            $price = $this->registry->get($id)->getPrice();
            $units += ($price->getMetal() + $price->getCrystal() + $price->getDeuterium()) * $amount;
        }

        return $units;
    }

    /**
     * Leave wrecks after a battle when enough was destroyed: $before are
     * the player's ships in the battle, $lost the destroyed ones. True when
     * a wreck field was formed or grew.
     *
     * @param array<int, int> $before
     * @param array<int, int> $lost
     */
    public function recordBattle(int $planetId, int $userId, array $before, array $lost, int $now, int $repairableFrom = 0): bool
    {
        $before = $this->wreckable($before);
        $lost = $this->wreckable($lost);
        $lostUnits = $this->units($lost);

        if ($lostUnits < self::MIN_UNITS || $lostUnits < self::MIN_SHARE * $this->units($before)) {
            return false;
        }

        $share = 1 - max(0, min(100, $this->settings->getInt('fleet_cdr'))) / 100;
        $wrecks = [];

        foreach ($lost as $id => $amount) {
            $wrecks[$id] = (int) floor($amount * $share);
        }

        return $this->addWrecks($this->planetIdAt($planetId), $userId, array_filter($wrecks), $now, $repairableFrom);
    }

    /**
     * Where the fleets started (the planet, also for a moon), their owners
     * and when they are back: fleet id => origin.
     *
     * @param list<int> $fleetIds
     *
     * @return array<int, array{planet: int, user: int, back: int}>
     */
    public function fleetOrigins(array $fleetIds): array
    {
        if ($fleetIds === []) {
            return [];
        }

        $rows = DB::table('fleets AS f')
            ->join('planets AS p', function ($join): void {
                $join->on('p.planet_galaxy', '=', 'f.fleet_start_galaxy')
                    ->on('p.planet_system', '=', 'f.fleet_start_system')
                    ->on('p.planet_planet', '=', 'f.fleet_start_planet')
                    ->where('p.planet_type', '=', PlanetTypesEnumerator::PLANET)
                    ->where('p.planet_destroyed', '=', 0);
            })
            ->whereIn('f.fleet_id', $fleetIds)
            ->get(['f.fleet_id', 'f.fleet_owner', 'f.fleet_end_time', 'p.planet_id', 'p.planet_user_id']);
        $origins = [];

        foreach ($rows as $row) {
            if ((int) $row->planet_user_id === (int) $row->fleet_owner) {
                $origins[(int) $row->fleet_id] = ['planet' => (int) $row->planet_id, 'user' => (int) $row->fleet_owner, 'back' => (int) $row->fleet_end_time];
            }
        }

        return $origins;
    }

    /**
     * The wreck field of the planet, or null.
     *
     * @phpstan-impure
     *
     * @return array{planet_id: int, user_id: int, ships: array<int, int>, created_at: int, expires_at: int, repairable_from: int, repair_started_at: int, repair_ends_at: int, repaired: array<int, int>}|null
     */
    public function field(int $planetId): ?array
    {
        $row = DB::table('wreck_fields')->where('planet_id', $planetId)->first();

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * The wreck fields of the planets that can still be seen (not burnt
     * up), by planet id, for the galaxy view.
     *
     * @param list<int> $planetIds
     *
     * @return array<int, array{planet_id: int, user_id: int, ships: array<int, int>, created_at: int, expires_at: int, repairable_from: int, repair_started_at: int, repair_ends_at: int, repaired: array<int, int>}>
     */
    public function visibleFields(array $planetIds, int $now): array
    {
        if ($planetIds === []) {
            return [];
        }

        $fields = [];

        foreach (DB::table('wreck_fields')->whereIn('planet_id', $planetIds)->get() as $row) {
            $field = self::fromRow($row);

            if ($field['repair_started_at'] > 0 || $field['expires_at'] > $now) {
                $fields[$field['planet_id']] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array{planet_id: int, user_id: int, ships: array<int, int>, created_at: int, expires_at: int, repairable_from: int, repair_started_at: int, repair_ends_at: int, repaired: array<int, int>}
     */
    private static function fromRow(\stdClass $row): array
    {
        return [
            'planet_id' => (int) $row->planet_id,
            'user_id' => (int) $row->user_id,
            'ships' => self::decode($row->ships),
            'created_at' => (int) $row->created_at,
            'expires_at' => (int) $row->expires_at,
            'repairable_from' => (int) $row->repairable_from,
            'repair_started_at' => (int) $row->repair_started_at,
            'repair_ends_at' => (int) $row->repair_ends_at,
            'repaired' => self::decode($row->repaired),
        ];
    }

    /**
     * The ships the space dock of the planet would repair.
     *
     * @param array<int, int> $wrecks
     *
     * @return array<int, int>
     */
    public function repairable(array $wrecks, int $dockLevel): array
    {
        $share = $this->repairShare($dockLevel);
        $ships = [];

        foreach ($wrecks as $id => $amount) {
            $ships[$id] = (int) floor($amount * $share);
        }

        return array_filter($ships);
    }

    /**
     * How long the repair takes: the shipyard's time, 30 minutes to 12
     * hours.
     *
     * @param array<int, int> $ships
     */
    public function repairTime(array $ships, int $shipyardLevel, int $naniteLevel): int
    {
        $time = 0;

        foreach ($ships as $id => $amount) {
            $time += $this->developments->developmentTime($id, 0, $shipyardLevel, $naniteLevel, 0, 0, false) * $amount;
        }

        return max(self::MIN_REPAIR_TIME, min(self::MAX_REPAIR_TIME, $time));
    }

    /**
     * Start repairing the wreck field of the player's planet. Error language
     * key, or null.
     */
    public function startRepair(int $userId, int $planetId, int $now): ?string
    {
        return DB::transaction(function () use ($userId, $planetId, $now): ?string {
            $field = $this->lockedField($userId, $planetId);

            if ($field === null || $field['expires_at'] <= $now && $field['repair_started_at'] === 0) {
                return 'sd_no_wreck_field';
            }

            if ($field['repair_started_at'] > 0) {
                return 'sd_repair_running';
            }

            if ($field['repairable_from'] > $now) {
                return 'sd_fleet_not_back';
            }

            $levels = DB::table('buildings')->where('building_planet_id', $planetId)->first(['building_space_dock', 'building_hangar', 'building_nano_factory']);
            $dock = (int) ($levels->building_space_dock ?? 0);

            if ($dock < 1) {
                return 'sd_no_space_dock';
            }

            $ships = $this->repairable($field['ships'], $dock);

            if ($ships === []) {
                return 'sd_nothing_to_repair';
            }

            DB::table('wreck_fields')->where('planet_id', $planetId)->update([
                'repair_started_at' => $now,
                'repair_ends_at' => $now + $this->repairTime($ships, (int) ($levels->building_hangar ?? 0), (int) ($levels->building_nano_factory ?? 0)),
                'repaired' => json_encode($ships),
            ]);

            return null;
        });
    }

    /**
     * Put the repaired ships back into service. Error language key, or null.
     */
    public function deploy(int $userId, int $planetId, int $now): ?string
    {
        return DB::transaction(function () use ($userId, $planetId, $now): ?string {
            $field = $this->lockedField($userId, $planetId);

            if ($field === null || $field['repair_started_at'] === 0) {
                return 'sd_no_repair';
            }

            if ($field['repair_ends_at'] > $now) {
                return 'sd_repair_running';
            }

            $this->addShips($planetId, $field['repaired']);
            DB::table('wreck_fields')->where('planet_id', $planetId)->delete();

            return null;
        });
    }

    /**
     * Burn up the fields nobody started to repair, and put repaired ships
     * into service 3 days after their repair.
     */
    public function settle(int $planetId, int $now): void
    {
        $field = $this->field($planetId);

        if ($field === null) {
            return;
        }

        if ($field['repair_started_at'] === 0 && $field['expires_at'] <= $now) {
            DB::table('wreck_fields')->where('planet_id', $planetId)->where('repair_started_at', 0)->delete();

            return;
        }

        if ($field['repair_started_at'] > 0 && $now >= $field['repair_ends_at'] + self::LIFETIME) {
            $this->deploy($field['user_id'], $planetId, $now);
        }
    }

    /**
     * @param array<int, int> $wrecks
     */
    private function addWrecks(int $planetId, int $userId, array $wrecks, int $now, int $repairableFrom): bool
    {
        if ($planetId === 0 || $wrecks === []) {
            return false;
        }

        return DB::transaction(function () use ($planetId, $userId, $wrecks, $now, $repairableFrom): bool {
            $row = DB::table('wreck_fields')->where('planet_id', $planetId)->lockForUpdate()->first();

            if ($row === null || (int) $row->repair_started_at === 0 && (int) $row->expires_at <= $now) {
                DB::table('wreck_fields')->where('planet_id', $planetId)->delete();
                DB::table('wreck_fields')->insert([
                    'planet_id' => $planetId,
                    'user_id' => $userId,
                    'ships' => json_encode($wrecks),
                    'created_at' => $now,
                    'expires_at' => $now + self::LIFETIME,
                    'repairable_from' => $repairableFrom,
                ]);

                return true;
            }

            // a running repair keeps its ships; the new wrecks are lost
            if ((int) $row->repair_started_at > 0) {
                return false;
            }

            $ships = self::decode($row->ships);

            foreach ($wrecks as $id => $amount) {
                $ships[$id] = ($ships[$id] ?? 0) + $amount;
            }

            DB::table('wreck_fields')->where('planet_id', $planetId)->update([
                'ships' => json_encode($ships),
                'expires_at' => $now + self::LIFETIME,
                'repairable_from' => max((int) $row->repairable_from, $repairableFrom),
            ]);

            return true;
        });
    }

    /**
     * @return array{planet_id: int, user_id: int, ships: array<int, int>, created_at: int, expires_at: int, repairable_from: int, repair_started_at: int, repair_ends_at: int, repaired: array<int, int>}|null
     */
    private function lockedField(int $userId, int $planetId): ?array
    {
        DB::table('wreck_fields')->where('planet_id', $planetId)->lockForUpdate()->first();
        $field = $this->field($planetId);

        return $field !== null && $field['user_id'] === $userId ? $field : null;
    }

    /**
     * @param array<int, int> $ships
     */
    private function addShips(int $planetId, array $ships): void
    {
        $columns = [];

        foreach ($ships as $id => $amount) {
            $column = $this->registry->get($id)->getName();
            $columns[$column] = DB::raw('`' . $column . '` + ' . (int) $amount);
        }

        if ($columns !== []) {
            DB::table('ships')->where('ship_planet_id', $planetId)->update($columns);
        }
    }

    /**
     * The planet of a moon: the space dock is there.
     */
    private function planetIdAt(int $planetId): int
    {
        $place = DB::table('planets')->where('planet_id', $planetId)->first(['planet_galaxy', 'planet_system', 'planet_planet', 'planet_type']);

        if ($place === null) {
            return 0;
        }

        if ((int) $place->planet_type !== PlanetTypesEnumerator::MOON) {
            return $planetId;
        }

        $planet = DB::table('planets')
            ->where('planet_galaxy', $place->planet_galaxy)
            ->where('planet_system', $place->planet_system)
            ->where('planet_planet', $place->planet_planet)
            ->where('planet_type', PlanetTypesEnumerator::PLANET)
            ->where('planet_destroyed', 0)
            ->value('planet_id');

        return is_numeric($planet) ? (int) $planet : 0;
    }

    /**
     * Only ships, without solar satellites and crawlers.
     *
     * @param array<int, int> $ships
     *
     * @return array<int, int>
     */
    private function wreckable(array $ships): array
    {
        return array_filter(
            $ships,
            fn (int $amount, int $id): bool => $amount > 0 &&
                !in_array($id, self::NO_WRECKS, true) &&
                $this->registry->has($id) &&
                $this->registry->get($id) instanceof Ship,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @return array<int, int>
     */
    private static function decode(mixed $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        $ships = [];

        foreach (is_array($data) ? $data : [] as $id => $amount) {
            if (is_numeric($id) && is_numeric($amount) && (int) $amount > 0) {
                $ships[(int) $id] = (int) $amount;
            }
        }

        return $ships;
    }
}
