<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\Enumerators\PlanetTypesEnumerator;
use Illuminate\Support\Facades\DB;

/**
 * The system espionage of warrior alliances (OGame 8): probes to every
 * target of a system at once, filtered by the player's options.
 */
class SystemEspionageService
{
    public const TARGETS = ['both', 'planets', 'moons'];
    public const ACTIVITY = ['both', 'active', 'inactive'];

    /** inactive: not online for a week */
    private const INACTIVE_AFTER = 7 * 86400;

    /**
     * The options from the form, within their limits.
     *
     * @param array<string, mixed> $input
     *
     * @return array{probes: int, targets: string, activity: string, skip_hours: int}
     */
    public function options(array $input, int $defaultProbes): array
    {
        $probes = $input['probes'] ?? $defaultProbes;
        $hours = $input['skip_hours'] ?? 0;
        $targets = $input['targets'] ?? 'both';
        $activity = $input['activity'] ?? 'both';

        return [
            'probes' => max(1, min(999, is_numeric($probes) ? (int) $probes : 1)),
            'targets' => in_array($targets, self::TARGETS, true) ? $targets : 'both',
            'activity' => in_array($activity, self::ACTIVITY, true) ? $activity : 'both',
            'skip_hours' => max(0, min(720, is_numeric($hours) ? (int) $hours : 0)),
        ];
    }

    /**
     * The targets of the system: the planets and moons of other players
     * that match the options, ordered by position.
     *
     * @param array{probes: int, targets: string, activity: string, skip_hours: int} $options
     *
     * @return list<array{planet_id: int, planet: int, type: int, owner: int}>
     */
    public function targets(int $userId, int $galaxy, int $system, array $options, int $now): array
    {
        $query = DB::table('planets AS p')
            ->join('users AS u', 'u.id', '=', 'p.planet_user_id')
            ->where('p.planet_galaxy', $galaxy)
            ->where('p.planet_system', $system)
            ->where('p.planet_planet', '<=', MAX_PLANET_IN_SYSTEM)
            ->where('p.planet_destroyed', 0)
            ->where('p.planet_user_id', '<>', $userId)
            ->whereIn('p.planet_type', match ($options['targets']) {
                'planets' => [PlanetTypesEnumerator::PLANET],
                'moons' => [PlanetTypesEnumerator::MOON],
                default => [PlanetTypesEnumerator::PLANET, PlanetTypesEnumerator::MOON],
            });

        if ($options['activity'] === 'active') {
            $query->where('u.onlinetime', '>=', $now - self::INACTIVE_AFTER);
        }

        if ($options['activity'] === 'inactive') {
            $query->where('u.onlinetime', '<', $now - self::INACTIVE_AFTER);
        }

        if ($options['skip_hours'] > 0) {
            $query->whereNotExists(function ($spied) use ($userId, $now, $options): void {
                $spied->from('espionage_log AS e')
                    ->whereColumn('e.planet_id', 'p.planet_id')
                    ->where('e.user_id', $userId)
                    ->where('e.spied_at', '>', $now - $options['skip_hours'] * 3600);
            });
        }

        $targets = [];

        foreach ($query->orderBy('p.planet_planet')->orderBy('p.planet_type')->get(['p.planet_id', 'p.planet_planet', 'p.planet_type', 'p.planet_user_id']) as $row) {
            $targets[] = [
                'planet_id' => (int) $row->planet_id,
                'planet' => (int) $row->planet_planet,
                'type' => (int) $row->planet_type,
                'owner' => (int) $row->planet_user_id,
            ];
        }

        return $targets;
    }

    /**
     * Note probes the player sent to the planet or moon at the coordinates.
     */
    public function recordAt(int $userId, int $galaxy, int $system, int $planet, int $type, int $now): void
    {
        $planetId = DB::table('planets')
            ->where('planet_galaxy', $galaxy)
            ->where('planet_system', $system)
            ->where('planet_planet', $planet)
            ->where('planet_type', $type)
            ->where('planet_destroyed', 0)
            ->value('planet_id');

        if (is_numeric($planetId)) {
            $this->record($userId, (int) $planetId, $now);
        }
    }

    /**
     * Note that the player sent probes to the planet or moon.
     */
    public function record(int $userId, int $planetId, int $now): void
    {
        DB::table('espionage_log')->updateOrInsert(['user_id' => $userId, 'planet_id' => $planetId], ['spied_at' => $now]);
    }
}
