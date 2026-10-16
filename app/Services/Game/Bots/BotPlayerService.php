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
 * view gets slow. Their fleets fly as {@see BotFleetPlanner} decides.
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

    public function __construct(
        private SettingsService $settings,
        private BotStrategy $strategy,
        private BotShipyard $shipyard,
        private BotFleetPlanner $fleetPlanner,
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
        if (!$this->settings->getBool('bots_play') || $now < $this->settings->getInt('bots_last_run') + self::TICK_GAP) {
            return;
        }

        $this->settings->write('bots_last_run', $now);

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
                foreach ($this->planetIds($botId) as $planetId) {
                    $this->playPlanet($user, $planetId, $now);
                }
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
        $this->orderShips($user, $planetId, $levels, $reserve);
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

        $building = $this->strategy->nextBuilding(
            $this->developmentData->levelsFromPlanet($model, $user),
            $numbers,
            $planetId === self::asInt($user['home_planet_id'] ?? 0),
            self::asInt($user['research_current_research'] ?? 0) !== 0
        );

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

        if (($levels[Buildings::BUILDING_LABORATORY] ?? 0) === 0) {
            return;
        }

        $tech = $this->strategy->nextResearch($levels);

        if ($tech !== null) {
            $this->researchQueue->add($bot, $model, $this->userWithResearch($user, $bot), $tech, false);
        }
    }

    /**
     * Ships and defences, like the shipyard page orders them, while the
     * shipyard is idle and not being upgraded.
     *
     * @param array<string, mixed> $user
     * @param array<int, int>      $levels
     * @param array<string, float> $reserve
     */
    private function orderShips(array $user, int $planetId, array $levels, array $reserve): void
    {
        $model = Planets::find($planetId);
        $planet = $this->planetRow($planetId);

        if ($model === null || $planet === [] || !in_array($model->planet_b_hangar_id, ['', '0', null], true) ||
            $model->buildingQueue()->whereIn('building_id', [Buildings::BUILDING_ROBOT_FACTORY, Buildings::BUILDING_NANO_FACTORY, Buildings::BUILDING_HANGAR])->exists()) {
            return;
        }

        $order = $this->shipyard->order(
            $levels,
            $this->shipyard->shipsOf($planet),
            $planetId === self::asInt($user['home_planet_id'] ?? 0),
            $this->fleetPlanner->wantsColony($user),
            ['metal' => $model->planet_metal, 'crystal' => $model->planet_crystal, 'deuterium' => $model->planet_deuterium],
            $reserve,
            MAX_FLEET_OR_DEFS_PER_ROW
        );

        if ($order === null) {
            return;
        }

        [$unit, $amount] = $order;
        $price = $this->developments->developmentPrice($unit, 0, false);

        DB::update(
            $this->prepareSql(
                'UPDATE `' . PLANETS . '` SET
                    `planet_b_hangar_id` = CONCAT(COALESCE(`planet_b_hangar_id`, \'\'), ?),
                    `planet_metal` = `planet_metal` - ?,
                    `planet_crystal` = `planet_crystal` - ?,
                    `planet_deuterium` = `planet_deuterium` - ?
                WHERE `planet_id` = ?;'
            ),
            [
                $unit . ',' . $amount . ';',
                ($price['metal'] ?? 0) * $amount,
                ($price['crystal'] ?? 0) * $amount,
                ($price['deuterium'] ?? 0) * $amount,
                $planetId,
            ]
        );
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
            ->where('planet_type', PlanetTypesEnumerator::PLANET)
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
