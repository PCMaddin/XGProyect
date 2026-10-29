<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Exceptions\UniverseFull;
use App\Models\Planets;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

class PlanetService
{
    private const LAST = ['lastsettedgalaxypos', 'lastsettedsystempos', 'lastsettedplanetpos'];

    /** the last place for a new home planet in a system */
    private const LAST_POSITION = 12;

    public function __construct(private SettingsService $settingsService)
    {
    }

    /**
     * The place of a new home planet: the next free one after the last
     * given out. Call it in the transaction that creates the planet: it
     * locks the last place, so two registrations at the same moment never
     * get the same one.
     *
     * @return array{galaxy: int, system: int, planet: int}
     *
     * @throws UniverseFull
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function calculateNewPlanetPosition(): array
    {
        $last = DB::table('options')->whereIn('name', self::LAST)->lockForUpdate()->pluck('value', 'name');
        $number = static fn (string $name): int => is_numeric($last[$name] ?? null) ? (int) $last[$name] : 0;

        $place = $this->freePlace(
            max($number('lastsettedgalaxypos'), 1),
            max($number('lastsettedsystempos'), 1),
            max($number('lastsettedplanetpos'), 4) // new users need to start at position 4
        );

        if ($place === null) {
            throw new UniverseFull();
        }

        $this->settingsService->write('lastsettedgalaxypos', $place['galaxy']);
        $this->settingsService->write('lastsettedsystempos', $place['system']);
        $this->settingsService->write('lastsettedplanetpos', $place['planet']);

        return $place;
    }

    /**
     * The first free place from the given one on: positions 4 to 12 of a
     * system, system after system, galaxy after galaxy, then from the start
     * again; one query per system. It called itself once per place taken,
     * and in a full universe without end, until PHP ran out of memory.
     *
     * @return array{galaxy: int, system: int, planet: int}|null
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    private function freePlace(int $galaxy, int $system, int $position): ?array
    {
        // every system once, the first again for the places before $position
        for ($systems = MAX_GALAXY_IN_WORLD * MAX_SYSTEM_IN_GALAXY; $systems >= 0; $systems--) {
            $taken = array_map(
                static fn (mixed $planet): int => is_numeric($planet) ? (int) $planet : 0,
                Planets::where('planet_galaxy', $galaxy)->where('planet_system', $system)->pluck('planet_planet')->all()
            );

            for (; $position <= self::LAST_POSITION; $position += PLANET_SEPARATION_FACTOR) {
                if (!in_array($position, $taken, true)) {
                    return ['galaxy' => $galaxy, 'system' => $system, 'planet' => $position];
                }
            }

            $position = 4;
            $system += SYSTEM_SEPARATION_FACTOR;

            if ($system > MAX_SYSTEM_IN_GALAXY) {
                $system = 1;
                $galaxy = $galaxy + GALAXY_SEPARATION_FACTOR > MAX_GALAXY_IN_WORLD ? 1 : $galaxy + GALAXY_SEPARATION_FACTOR;
            }
        }

        return null;
    }
}
