<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\TimingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Entity\FleetEntity;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;
use Xgp\App\Core\Objects;
use Xgp\App\Libraries\FleetsLib;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\Game\Fleets;
use App\Libraries\Premium\Premium;
use App\Libraries\Research\Researches;
use Xgp\App\Libraries\Users;

/**
 * Fleet movements overview: list the player's fleets in flight and let them
 * recall a fleet that is still on its way out.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class MovementController extends BaseController
{
    use PreparesLegacySql;

    public const REDIRECT_TARGET = 'game.php?page=movement';

    /** @var array<string, mixed> */
    private array $user = [];

    private Fleets $fleets;

    private Researches $research;

    private Premium $premium;

    private Objects $objects;

    public function __construct(
        private FormatService $formatService,
        private FleetsService $fleetsService,
        private OfficerService $officerService,
        private TimingService $timingService,
    ) {
    }

    public function __invoke(Request $request): View|RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Fleet));

        $this->user = Users::getInstance()->getUserData();
        $this->objects = new Objects();

        $this->setUpFleets();

        $redirect = $this->runAction($request);

        if ($redirect !== null) {
            return $redirect;
        }

        return $this->buildPage();
    }

    private function setUpFleets(): void
    {
        $userId = $this->userInt('id');

        $rows = $userId > 0 ? array_map(
            fn (object $row): array => get_object_vars($row),
            DB::select(
                $this->prepareSql('SELECT f.* FROM `' . FLEETS . '` f WHERE f.`fleet_owner` = ?;'),
                [$userId]
            )
        ) : [];

        $this->fleets = new Fleets($rows, $userId);
        $this->research = new Researches([$this->user]);
        $this->premium = new Premium([$this->user]);
    }

    private function runAction(Request $request): ?RedirectResponse
    {
        if ($request->query('action') === 'return') {
            return $this->execFleetReturn($request);
        }

        return null;
    }

    private function buildPage(): View
    {
        return view('movement.view', [
            'fleets' => $this->fleets->getFleetsCount(),
            'max_fleets' => $this->fleetsService->getMaxFleets(
                $this->research->getCurrentResearch()->getResearchComputerTechnology(),
                $this->officerService->isOfficerActive($this->premium->getCurrentPremium()->getPremiumOfficierAdmiral(), time())
            ),
            'expeditions' => $this->fleets->getExpeditionsCount(),
            'max_expeditions' => $this->fleetsService->getMaxExpeditions(
                $this->research->getCurrentResearch()->getResearchAstrophysics()
            ),
            'list_of_movements' => $this->buildMovements(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMovements(): array
    {
        if ($this->fleets->getFleetsCount() <= 0) {
            return [$this->emptyMovementRow()];
        }

        $list = [];
        $count = 0;

        foreach ($this->fleets->getFleets() as $fleet) {
            $mess = $this->asInt($fleet->getFleetMess());

            $list[] = [
                'num' => ++$count,
                'fleet_mission' => $this->missionName($this->asInt($fleet->getFleetMission())),
                'title' => $this->buildTitleBlock($mess),
                'tooltip' => $this->buildToolTipBlock($mess),
                'fleet_amount' => $this->formatService->prettyNumber($this->asInt($fleet->getFleetAmount())),
                'fleet' => $this->buildShipsBlock($this->asString($fleet->getFleetArray())),
                'fleet_start' => $this->formatService->prettyCoords(
                    $this->asInt($fleet->getFleetStartGalaxy()),
                    $this->asInt($fleet->getFleetStartSystem()),
                    $this->asInt($fleet->getFleetStartPlanet())
                ),
                'fleet_start_time' => $this->timingService->formatExtendedDate($this->asInt($fleet->getFleetCreation())),
                'fleet_end' => $this->formatService->prettyCoords(
                    $this->asInt($fleet->getFleetEndGalaxy()),
                    $this->asInt($fleet->getFleetEndSystem()),
                    $this->asInt($fleet->getFleetEndPlanet())
                ),
                'fleet_end_time' => $this->timingService->formatExtendedDate($this->asInt($fleet->getFleetStartTime())),
                'fleet_arrival' => $this->timingService->formatExtendedDate($this->asInt($fleet->getFleetEndTime())),
                'fleet_actions' => $this->buildActionsBlock($fleet),
            ];
        }

        return $list;
    }

    /**
     * @return array<string, string>
     */
    private function emptyMovementRow(): array
    {
        return [
            'num' => '-',
            'fleet_mission' => '-',
            'title' => '',
            'tooltip' => '',
            'fleet_amount' => '-',
            'fleet' => '',
            'fleet_start' => '-',
            'fleet_start_time' => '-',
            'fleet_end' => '-',
            'fleet_end_time' => '-',
            'fleet_arrival' => '-',
            'fleet_actions' => '-',
        ];
    }

    private function missionName(int $mission): string
    {
        $types = trans('game/missions.type_mission');

        if (is_array($types) && isset($types[$mission]) && is_scalar($types[$mission])) {
            return (string) $types[$mission];
        }

        return '';
    }

    private function buildTitleBlock(int $fleetMess): string
    {
        return (string) ($this->fleetsService->isFleetReturning($fleetMess)
            ? __('game/fleet.fl_r')
            : __('game/fleet.fl_a'));
    }

    private function buildToolTipBlock(int $fleetMess): string
    {
        return (string) ($this->fleetsService->isFleetReturning($fleetMess)
            ? __('game/fleet.fl_returning')
            : __('game/fleet.fl_onway'));
    }

    private function buildShipsBlock(string $fleetArray): string
    {
        $objects = $this->objects->getObjects();
        $ships = FleetsLib::getFleetShipsArray($fleetArray);
        $tooltips = [];

        foreach ($ships as $ship => $amount) {
            if (!is_numeric($ship)) {
                continue;
            }

            $name = is_array($objects) && isset($objects[$ship]) && is_scalar($objects[$ship])
                ? (string) $objects[$ship]
                : '';

            $tooltips[] = __('game/ships.' . $name) . ' :' . $this->asInt($amount);
        }

        return $tooltips === [] ? '' : implode("\n", $tooltips);
    }

    private function buildActionsBlock(FleetEntity $fleet): string
    {
        if ($this->asInt($fleet->getFleetMess()) !== 0) {
            return '-';
        }

        $fleetId = $this->asInt($fleet->getFleetId());

        $actions = '<form action="game.php?page=movement&action=return" method="post">'
            . '<input type="hidden" name="fleetid" value="' . $fleetId . '">'
            . '<input type="submit" name="send" value="' . __('game/fleet.fl_send_back') . '">'
            . '</form>';

        if ($this->asInt($fleet->getFleetMission()) === Missions::ATTACK) {
            $content = '<input type="button" value="' . __('game/fleet.fl_acs') . '">';
            $attributes = 'onClick="f(\'game.php?page=federationlayer&fleet=' . $fleetId . '\', \'\')"';

            $actions .= $this->formatService->link('#', $content, '', $attributes);
        }

        return $actions;
    }

    private function execFleetReturn(Request $request): ?RedirectResponse
    {
        $fleetId = $request->integer('fleetid');

        if ($fleetId <= 0) {
            return null;
        }

        $fleet = $this->fleets->getOwnFleetById($fleetId);

        if ($fleet === null || $this->asInt($fleet->getFleetMess()) === 1) {
            return null;
        }

        $this->returnFleet($fleet, $this->userInt('id'));

        return redirect(self::REDIRECT_TARGET);
    }

    private function returnFleet(FleetEntity $fleet, int $userId): void
    {
        DB::transaction(function () use ($fleet, $userId): void {
            $this->releaseAcsGroup($fleet);

            $baseTime = time();
            $creation = $this->asInt($fleet->getFleetCreation());
            $elapsed = $baseTime - $creation;
            $flightLength = $this->asInt($fleet->getFleetStartTime()) - $creation;
            $returnTime = $baseTime + $elapsed;

            if ($this->asInt($fleet->getFleetEndStay()) !== 0 && $elapsed > $flightLength) {
                $returnTime = $baseTime + $flightLength;
            }

            DB::update(
                $this->prepareSql(
                    'UPDATE `' . FLEETS . '` f SET
                        f.`fleet_start_time` = ?, f.`fleet_end_stay` = 0, f.`fleet_end_time` = ?,
                        f.`fleet_target_owner` = ?, f.`fleet_mess` = 1
                    WHERE f.`fleet_id` = ?;'
                ),
                [$baseTime, $returnTime, $userId, $this->asInt($fleet->getFleetId())]
            );
        });
    }

    private function releaseAcsGroup(FleetEntity $fleet): void
    {
        $group = $this->asInt($fleet->getFleetGroup());

        if ($group <= 0) {
            return;
        }

        $mission = $this->asInt($fleet->getFleetMission());

        if ($mission === Missions::ATTACK) {
            $row = DB::selectOne(
                $this->prepareSql('SELECT af.`acs_owner` FROM `' . ACS . '` af WHERE af.`acs_id` = ?;'),
                [$group]
            );
            $acsOwner = is_object($row) ? $this->asInt(get_object_vars($row)['acs_owner'] ?? 0) : 0;

            if ($acsOwner > 0 && $acsOwner === $this->asInt($fleet->getFleetOwner())) {
                DB::delete($this->prepareSql('DELETE FROM `' . ACS . '` WHERE `acs_id` = ?;'), [$group]);
                DB::update(
                    $this->prepareSql('UPDATE `' . FLEETS . '` f SET f.`fleet_group` = 0 WHERE f.`fleet_group` = ?;'),
                    [$group]
                );
            }
        }

        if ($mission === Missions::ACS) {
            DB::update(
                $this->prepareSql('UPDATE `' . FLEETS . '` f SET f.`fleet_group` = 0 WHERE f.`fleet_id` = ?;'),
                [$this->asInt($fleet->getFleetId())]
            );
        }
    }

    private function userInt(string $key): int
    {
        $value = $this->user[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
