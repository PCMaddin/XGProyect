<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\GameObjects\GameObjectRegistry;
use App\Enums\PlayerClass;
use App\Models\Buildings;
use App\Exceptions\NoLongerOnPlanet;
use App\Models\Planets;
use App\Models\Research;
use App\Models\ResearchQueue;
use App\Models\User;
use App\Models\UsersStatistics;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Support\Concerns\ReadsMixedValues;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Core\Enumerators\BuildingsEnumerator;
use App\Core\Enumerators\ResearchEnumerator;
use App\Libraries\StatisticsLibrary;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class ResearchQueueService
{
    use ReadsMixedValues;

    public const MAX_QUEUE_SIZE = 5;

    public function __construct(
        private GameObjectRegistry $registry,
        private QueueSequenceService $queueSequenceService,
        private DevelopmentDataService $developmentDataService,
        private DevelopmentsService $developmentsService,
        private PlanetStock $stock,
    ) {
    }

    /**
     * @param  array<string,mixed>  $userData
     */
    public function add(User $user, Planets $planet, array $userData, int $techId, bool $technocrateActive): bool
    {
        $queue = $user->researchQueue()->orderBy('position')->get();
        $count = $queue->count();

        if ($count >= self::MAX_QUEUE_SIZE) {
            return false;
        }

        $levels = $this->developmentDataService->levelsFromPlanet($planet, $userData);

        if (!$this->developmentsService->isDevelopmentAllowed($techId, $levels)) {
            return false;
        }

        $techCol = $this->registry->get($techId)->getName();
        $alreadyQueued = $queue->where('tech_id', $techId)->count();
        $currentLevel = self::asInt($userData[$techCol] ?? 0);
        $levelForCalc = $currentLevel + $alreadyQueued;

        $labLevel = $this->labLevel($user, $planet);
        $astrophysicsCol = $this->registry->get(ResearchEnumerator::RESEARCH_ASTROPHYSICS)->getName();
        $astrophysicsLevel = self::asInt($userData[$astrophysicsCol] ?? 0);

        $duration = $this->developmentsService->developmentTime(
            $techId,
            $levelForCalc,
            0,
            0,
            $labLevel,
            $astrophysicsLevel,
            $technocrateActive,
            PlayerClass::fromInt($user->player_class)
        );

        $endTime = (int) $queue->last()?->end_time + $duration;

        if ($count === 0) {
            if (!$this->developmentsService->isDevelopmentPayable(
                $this->developmentDataService->planetResources($planet),
                $techId,
                $levelForCalc
            )) {
                return false;
            }

            $endTime = time() + $duration;

            // paid only from what is still there (see PlanetStock)
            try {
                $this->stock->spend($planet, $this->developmentsService->developmentPrice($techId, $levelForCalc));
            } catch (NoLongerOnPlanet) {
                return false;
            }

            $planet->planet_b_tech_id = $techId;
            $planet->planet_b_tech = $endTime;
            $planet->save();

            $user->research->research_current_research = $planet->planet_id;
            $user->research->save();
        }

        ResearchQueue::create([
            'user_id' => $user->id,
            'planet_id' => $planet->planet_id,
            'position' => $count + 1,
            'tech_id' => $techId,
            'target_level' => $levelForCalc + 1,
            'duration' => $duration,
            'end_time' => $endTime,
        ]);

        return true;
    }

    public function cancel(User $user): bool
    {
        $item = $user->researchQueue()->where('position', 1)->first();

        if ($item === null) {
            return false;
        }

        // refunded once: only the request that removed the research gets it back
        if (ResearchQueue::whereKey($item->getKey())->delete() !== 1) {
            return false;
        }

        $workingPlanet = Planets::find($item->planet_id);

        if ($workingPlanet !== null) {
            $this->stock->refund($workingPlanet, $this->developmentsService->developmentPrice($item->tech_id, $item->target_level - 1));
            $workingPlanet->planet_b_tech_id = 0;
            $workingPlanet->planet_b_tech = 0;
            $workingPlanet->save();
        }
        $remaining = $user->researchQueue()->orderBy('position')->get();

        if ($remaining->isNotEmpty()) {
            $currentTime = time();
            $astrophysicsCol = $this->registry->get(ResearchEnumerator::RESEARCH_ASTROPHYSICS)->getName();
            $astrophysicsLevel = (int) $user->research->$astrophysicsCol;
            $technocrateActive = $user->premium->premium_officier_technocrat > $currentTime;

            $this->rebuildQueueAfterHeadRemoval(
                $remaining,
                $item->tech_id,
                $currentTime,
                fn (ResearchQueue $queuedItem): int => $this->resolveDuration(
                    $user,
                    $queuedItem,
                    $astrophysicsLevel,
                    $technocrateActive
                )
            );

            foreach ($remaining as $queuedItem) {
                $queuedItem->save();
            }
        }

        $user->research->research_current_research = 0;
        $user->research->save();
        $this->startNext($user);

        return true;
    }

    public function removeAt(User $user, int $position): void
    {
        if ($position <= 1) {
            return;
        }

        $this->queueSequenceService->removeTailOccurrenceAtPosition(
            $user->researchQueue()->orderBy('position')->get(),
            $position,
            static fn (ResearchQueue $item): int => $item->tech_id
        );
    }

    public function processCompletions(User $user): void
    {
        while (true) {
            $item = $user->researchQueue()->where('position', 1)->first();

            if ($item === null || $item->end_time > time()) {
                break;
            }

            // only the request that removes the finished research raises the
            // level, and by one: two at once raised it twice
            if (ResearchQueue::whereKey($item->getKey())->delete() !== 1) {
                continue;
            }

            $techCol = $this->registry->get($item->tech_id)->getName();
            Research::where('research_user_id', $user->id)->update([$techCol => DB::raw('`' . $techCol . '` + 1'), 'research_current_research' => 0]);
            $user->research->refresh();
            $newLevel = (int) $user->research->$techCol;

            $points = StatisticsLibrary::calculatePoints((string) $item->tech_id, $newLevel, 'tech');

            UsersStatistics::where('user_statistic_user_id', $user->id)
                ->increment('user_statistic_technology_points', $points);

            $activePlanet = Planets::find($item->planet_id);

            if ($activePlanet !== null) {
                $activePlanet->planet_b_tech_id = 0;
                $activePlanet->planet_b_tech = 0;
                $activePlanet->save();
            }

            $this->shiftPositionsDown($user);
            $this->startNext($user);
        }
    }

    /**
     * Starts the research now first in line, paying it; one that cannot be
     * paid any more is dropped (as a building is) and the next one tried.
     */
    private function startNext(User $user): void
    {
        while (($next = $user->researchQueue()->where('position', 1)->first()) !== null) {
            if ($this->startItem($user, $next)) {
                return;
            }

            if (ResearchQueue::whereKey($next->getKey())->delete() === 1) {
                $this->shiftPositionsDown($user);
            }
        }
    }

    /**
     * Shorten the running research and those after it by up to $seconds
     * (item "newtron"). False when nothing is being researched.
     */
    public function speedUp(int $userId, int $seconds): bool
    {
        $queue = ResearchQueue::where('user_id', $userId)->orderBy('position')->get();
        $first = $queue->first();

        if (!$first instanceof ResearchQueue || $first->end_time <= time()) {
            return false;
        }

        $cut = min($seconds, $first->end_time - time());

        foreach ($queue as $item) {
            $item->end_time -= $cut;
            $item->save();
        }

        Planets::where('planet_id', $first->planet_id)->update(['planet_b_tech' => $first->end_time]);

        return true;
    }

    public function labLevel(User $user, Planets $planet): int
    {
        $intergalCol = $this->registry->get(ResearchEnumerator::RESEARCH_INTERGALACTIC_RESEARCH_NETWORK)->getName();
        $intergalLevel = (int) $user->research->$intergalCol;

        if ($intergalLevel < 1) {
            $labCol = $this->registry->get(BuildingsEnumerator::BUILDING_LABORATORY)->getName();

            return (int) ($planet->buildings->$labCol ?? 0);
        }

        // with the network, all laboratories of the player add up (decided;
        // the former ORDER BY/LIMIT suggested only the best level+1, but
        // LIMIT does not limit a SUM, and strict MySQL rejects ORDER BY on a
        // non-aggregated column there)
        return self::asInt(Buildings::query()
            ->join('planets', 'planet_id', '=', 'building_planet_id')
            ->where('planet_user_id', $user->id)
            ->sum('building_laboratory'));
    }

    /**
     * Pays and starts a queued research; false if its planet cannot pay it
     * (it was never paid when queued: it went below zero before).
     */
    private function startItem(User $user, ResearchQueue $item): bool
    {
        $planet = Planets::find($item->planet_id);

        if ($planet === null) {
            return false;
        }

        try {
            $this->stock->spend($planet, $this->developmentsService->developmentPrice($item->tech_id, $item->target_level - 1));
        } catch (NoLongerOnPlanet) {
            return false;
        }

        $planet->planet_b_tech_id = $item->tech_id;
        $planet->planet_b_tech = $item->end_time;
        $planet->save();

        $user->research->research_current_research = $item->planet_id;
        $user->research->save();

        return true;
    }

    /**
     * @param  Collection<int, ResearchQueue>  $queue
     */
    private function rebuildQueueAfterHeadRemoval(
        Collection $queue,
        int $removedTechId,
        int $startTime,
        callable $durationResolver,
    ): void {
        $this->queueSequenceService->rebuildAfterHeadRemoval(
            $queue,
            $removedTechId,
            $startTime,
            static fn (ResearchQueue $item): int => $item->tech_id,
            static function (ResearchQueue $item): void {
                $item->target_level -= 1;
            },
            $durationResolver
        );
    }

    private function resolveDuration(
        User $user,
        ResearchQueue $item,
        int $astrophysicsLevel,
        bool $technocrateActive,
    ): int {
        $planet = Planets::with('buildings')->find($item->planet_id);

        if ($planet === null) {
            return $item->duration;
        }

        return $this->developmentsService->developmentTime(
            $item->tech_id,
            $item->target_level - 1,
            0,
            0,
            $this->labLevel($user, $planet),
            $astrophysicsLevel,
            $technocrateActive,
            PlayerClass::fromInt($user->player_class)
        );
    }

    private function shiftPositionsDown(User $user): void
    {
        foreach ($user->researchQueue()->orderBy('position')->get() as $item) {
            $item->position -= 1;
            $item->save();
        }
    }
}
