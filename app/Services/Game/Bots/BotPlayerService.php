<?php

declare(strict_types=1);

namespace App\Services\Game\Bots;

use App\Core\Concerns\PreparesSql;
use App\Core\Enumerators\BuildingsEnumerator as Buildings;
use App\Core\Enumerators\PlanetTypesEnumerator;
use App\Libraries\UpdatesLibrary;
use App\Models\Planets;
use App\Models\User;
use App\Services\Game\BuildingQueueService;
use App\Services\Game\DevelopmentDataService;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\Game\ResearchQueueService;
use App\Services\SettingsService;
use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lets the bots play: on the game tick, the bots that are due bring their
 * planets up to date like a player's page view does, then build, research
 * and order defences through the same queues and rules as players. A bot
 * acts every 10 to 20 minutes; at most a few bots act per tick so no page
 * view gets slow. Their fleets fly as {@see BotFleetPlanner} decides, what
 * they do with their alliance as {@see BotCoordinator} does, and they trade
 * as {@see BotTrader} does.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class BotPlayerService
{
    use PreparesSql;
    use ReadsMixedValues;

    /** seconds between two ticks that let bots act */
    private const TICK_GAP = 60;

    /** bots acting per tick */
    private const BOTS_PER_TICK = 10;

    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function __construct(
        private SettingsService $settings,
        private BotStrategy $strategy,
        private BotShipyardTurn $shipyardTurn,
        private BotFleetPlanner $fleetPlanner,
        private BotTrader $trader,
        private BotCoordinator $coordinator,
        private BuildingQueueService $buildingQueue,
        private ResearchQueueService $researchQueue,
        private DevelopmentDataService $developmentData,
        private DevelopmentsService $developments,
    ) {
    }

    /**
     * Let the due bots act, if bots play and the last run is a minute ago.
     */
    public function playDue(int $now): void
    {
        $lastRun = $this->settings->getInt('bots_last_run');

        // one page view of those at the same moment plays the bots
        if (!$this->settings->getBool('bots_play') || $now < $lastRun + self::TICK_GAP || !$this->settings->claim('bots_last_run', $lastRun, $now)) {
            return;
        }

        $bots = User::where('user_is_bot', true)
            ->where('user_bot_next_action', '<=', $now)
            ->orderBy('user_bot_next_action')
            ->limit(self::BOTS_PER_TICK)
            ->pluck('id');

        foreach ($bots as $botId) {
            $this->play(self::asInt($botId), $now);
        }
    }

    /**
     * One turn of one bot. A failing turn is reported and the bot tries
     * again next time; it never ends the page view that runs the tick.
     */
    public function play(int $botId, int $now): void
    {
        try {
            $user = $this->userRow($botId);

            if ($user !== [] && self::asInt($user['preference_vacation_mode'] ?? 0) === 0) {
                $this->coordinator->joinAlliance($botId, $now);
                $user = $this->userRow($botId);

                foreach ($this->planetIds($botId) as $planetId) {
                    $this->playPlanet($user, $planetId, $now);
                }

                $this->trader->act($user, self::asInt($user['home_planet_id'] ?? 0), $now);
            }
        } catch (Throwable $e) {
            report($e);
        }

        User::where('id', $botId)->update([
            'onlinetime' => $now,
            'user_bot_next_action' => $now + random_int(600, 1200),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function playPlanet(array $user, int $planetId, int $now): void
    {
        $planet = $this->planetRow($planetId);

        if ($planet === []) {
            return;
        }

        // what a page view of the bot would do: production, finished orders
        UpdatesLibrary::updatePlanetResources($user, $planet, $now);
        UpdatesLibrary::updateBuildingsQueue($planet, $user);
        UpdatesLibrary::updateResearchQueue($planet, $user);

        $numbers = $this->numbers($planet);

        $this->build($user, $planetId, $numbers);
        $this->research($user, $planetId);

        $model = Planets::with(['buildings'])->find($planetId);

        if ($model === null) {
            return;
        }

        // keep what the next building will cost
        $levels = $this->developmentData->levelsFromPlanet($model, $user);
        $next = $this->strategy->nextBuilding($levels, $numbers, false, true);
        $reserve = $next === null ? [] : $this->developments->developmentPrice($next, $levels[$next] ?? 0);

        $this->fleetPlanner->act($user, $this->planetRow($planetId), $reserve, $now);
        $this->coordinator->act($user, $this->planetRow($planetId), $now);
        $this->shipyardTurn->act($user, $this->planetRow($planetId), $levels, $reserve, $now);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, float> $numbers stock, storage capacity and energy after the update
     */
    private function build(array $user, int $planetId, array $numbers): void
    {
        $model = Planets::with(['buildings'])->find($planetId);

        if ($model === null || $model->buildingQueue()->exists()) {
            return;
        }

        $levels = $this->developmentData->levelsFromPlanet($model, $user);
        $home = $planetId === self::asInt($user['home_planet_id'] ?? 0);

        $moon = $model->planet_type === PlanetTypesEnumerator::MOON;
        // a home planet saving for its missile silo builds nothing else
        $silo = $home && !$moon && $this->coordinator->savesForSilo($user, $levels) ? $this->strategy->nextSiloStep($levels, $numbers) : null;
        $building = $moon
            ? $this->strategy->nextMoonBuilding($levels, $model->planet_field_max - $model->planet_field_current)
            : $silo ?? $this->strategy->nextBuilding($levels, $numbers, $home, self::asInt($user['research_current_research'] ?? 0) !== 0);

        if ($building !== null) {
            $this->buildingQueue->add($model, $user, $building);
        }
    }

    /**
     * Research runs on the home planet, not while its laboratory is built.
     *
     * @param array<string, mixed> $user
     */
    private function research(array $user, int $planetId): void
    {
        if ($planetId !== self::asInt($user['home_planet_id'] ?? 0)) {
            return;
        }

        $model = Planets::with(['buildings'])->find($planetId);
        $bot = User::with(['research', 'researchQueue'])->find(self::asInt($user['id'] ?? 0));

        if ($model === null || $bot === null || $bot->researchQueue->isNotEmpty() ||
            $model->buildingQueue()->where('building_id', Buildings::BUILDING_LABORATORY)->exists()) {
            return;
        }

        $levels = $this->developmentData->levelsFromPlanet($model, $this->userWithResearch($user, $bot));

        // a bot saving for its missile silo does not spend on research
        if (($levels[Buildings::BUILDING_LABORATORY] ?? 0) === 0 || $this->coordinator->savesForSilo($user, $levels)) {
            return;
        }

        $tech = $this->strategy->nextResearch($levels, $this->fleetPlanner->freeColonySlots($user));

        if ($tech !== null) {
            $this->researchQueue->add($bot, $model, $this->userWithResearch($user, $bot), $tech, false);
        }
    }

    /**
     * The research levels as the queue services read them from the user row.
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    private function userWithResearch(array $user, User $bot): array
    {
        return array_merge($user, $bot->research->getAttributes());
    }

    /**
     * The same row a player's page view loads (user, premium, preferences,
     * research, alliance class).
     *
     * @return array<string, mixed>
     */
    private function userRow(int $botId): array
    {
        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT u.*, pre.*, pr.*, r.*, a.alliance_name, a.alliance_class
                FROM `' . USERS . '` AS u
                INNER JOIN `' . PREFERENCES . '` AS pr ON pr.preference_user_id = u.id
                INNER JOIN `' . PREMIUM . '` AS pre ON pre.premium_user_id = u.id
                INNER JOIN `' . RESEARCH . '` AS r ON r.research_user_id = u.id
                LEFT JOIN `' . ALLIANCE . '` AS a ON a.alliance_id = u.ally_id
                WHERE u.`id` = ? AND u.`user_is_bot` = 1
                LIMIT 1;'
            ),
            [$botId]
        );

        return $row !== null ? (array) $row : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function planetRow(int $planetId): array
    {
        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT p.*, b.*, d.*, s.*
                FROM `' . PLANETS . '` AS p
                INNER JOIN `' . BUILDINGS . '` AS b ON b.building_planet_id = p.`planet_id`
                INNER JOIN `' . DEFENSES . '` AS d ON d.defense_planet_id = p.`planet_id`
                INNER JOIN `' . SHIPS . '` AS s ON s.ship_planet_id = p.`planet_id`
                WHERE p.`planet_id` = ?;'
            ),
            [$planetId]
        );

        return $row !== null ? (array) $row : [];
    }

    /**
     * @return list<int>
     */
    private function planetIds(int $botId): array
    {
        $ids = Planets::where('planet_user_id', $botId)
            ->whereIn('planet_type', [PlanetTypesEnumerator::PLANET, PlanetTypesEnumerator::MOON])
            ->where('planet_destroyed', 0)
            ->pluck('planet_id')
            ->all();

        return array_values(array_map(static fn (mixed $id): int => self::asInt($id), $ids));
    }

    /**
     * @param array<string, mixed> $planet
     *
     * @return array<string, float>
     */
    private function numbers(array $planet): array
    {
        $numbers = [];

        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $numbers['planet_' . $resource] = (float) self::asInt($planet['planet_' . $resource] ?? 0);
            $numbers['planet_' . $resource . '_max'] = (float) self::asInt($planet['planet_' . $resource . '_max'] ?? 0);
            $numbers['planet_' . $resource . '_perhour'] = (float) self::asInt($planet['planet_' . $resource . '_perhour'] ?? 0);
        }

        $numbers['planet_energy_max'] = (float) self::asInt($planet['planet_energy_max'] ?? 0);
        $numbers['planet_energy_used'] = (float) self::asInt($planet['planet_energy_used'] ?? 0);

        return $numbers;
    }
}
