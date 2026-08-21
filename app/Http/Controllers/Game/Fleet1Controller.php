<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\FleetsService;
use App\Services\Game\Formulas\OfficerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\ShipsEnumerator as Ships;
use Xgp\App\Core\Objects;
use App\Libraries\Functions;
use App\Libraries\Game\Fleets;
use App\Libraries\Premium\Premium;
use App\Libraries\Research\Researches;
use Xgp\App\Libraries\Users;

/**
 * Fleet wizard step 1: pick the ships to send. Posts the selection forward to
 * step 2 (fleet2).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Fleet1Controller extends BaseController
{
    use PreparesLegacySql;

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    private Fleets $fleets;

    private Researches $research;

    private Premium $premium;

    private Objects $objects;

    private int $shipCount = 0;

    public function __construct(
        private FormatService $formatService,
        private FleetsService $fleetsService,
        private OfficerService $officerService,
    ) {
    }

    public function __invoke(Request $request): View
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Fleet));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();
        $this->objects = new Objects();

        $this->setUpFleets();

        return $this->buildPage($request);
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

    private function buildPage(Request $request): View
    {
        $listOfShips = $this->buildListOfShips();

        return view('fleet.fleet1_view', array_merge(
            [
                'fleets' => $this->fleets->getFleetsCount(),
                'max_fleets' => $this->fleetsService->getMaxFleets(
                    $this->research->getCurrentResearch()->getResearchComputerTechnology(),
                    $this->officerService->isOfficerActive($this->premium->getCurrentPremium()->getPremiumOfficierAdmiral(), time())
                ),
                'expeditions' => $this->fleets->getExpeditionsCount(),
                'max_expeditions' => $this->fleetsService->getMaxExpeditions(
                    $this->research->getCurrentResearch()->getResearchAstrophysics()
                ),
                'no_slot' => $this->buildNoSlotBlock(),
                'list_of_ships' => $listOfShips,
                'none_max_selector' => $this->buildActionsBlock(),
                'no_ships' => $this->buildNoShipsBlock(),
                'continue_button' => $this->buildContinueBlock(),
            ],
            $this->setInputsData($request)
        ));
    }

    private function buildNoSlotBlock(): ?string
    {
        return $this->checkAvailableSlot() ? null : view('fleet.fleet1_noslots_row')->render();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildListOfShips(): array
    {
        $objects = $this->objects->getObjects();
        $planetId = $this->planetInt('planet_id');

        $shipsRow = $planetId > 0 ? DB::selectOne(
            $this->prepareSql(
                'SELECT
                    s.`ship_small_cargo_ship`, s.`ship_big_cargo_ship`, s.`ship_light_fighter`,
                    s.`ship_heavy_fighter`, s.`ship_cruiser`, s.`ship_battleship`, s.`ship_colony_ship`,
                    s.`ship_recycler`, s.`ship_espionage_probe`, s.`ship_bomber`, s.`ship_solar_satellite`,
                    s.`ship_destroyer`, s.`ship_deathstar`, s.`ship_reaper`
                FROM `' . SHIPS . '` AS s
                WHERE s.`ship_planet_id` = ?;'
            ),
            [$planetId]
        ) : null;

        if (!is_object($shipsRow)) {
            return [];
        }

        $list = [];

        foreach (get_object_vars($shipsRow) as $shipName => $rawAmount) {
            $amount = $this->asInt($rawAmount);

            if ($amount === 0 || !is_string($shipName)) {
                continue;
            }

            $this->shipCount += $amount;
            $shipId = is_array($objects) ? $this->asInt(array_search($shipName, $objects, true)) : 0;

            $list[] = [
                'ship_name' => $this->buildShipName($shipName, $shipId),
                'ship_amount' => $this->formatService->prettyNumber($amount),
                'max_ships_link' => $this->buildMaxShipsLink($shipId) ?? '-',
                'ships_input' => $this->buildShipsInput($shipId) ?? '-',
                'ship_id' => $shipId,
                'max_ships' => $amount,
                'consumption' => $this->fleetsService->shipConsumption($shipId, $this->driveLevel('combustion'), $this->driveLevel('impulse'), $this->driveLevel('hyperspace')),
                'speed' => $this->fleetsService->getShipSpeed($shipId, $this->driveLevel('combustion'), $this->driveLevel('impulse'), $this->driveLevel('hyperspace')),
                'capacity' => $this->fleetsService->getMaxStorage(
                    $this->asInt($this->objects->getPrice($shipId, 'capacity')),
                    $this->research->getCurrentResearch()->getResearchHyperspaceTechnology()
                ),
            ];
        }

        return $list;
    }

    private function buildShipName(string $shipName, int $shipId): string
    {
        $title = __('game/fleet.fl_speed_title')
            . $this->fleetsService->getShipSpeed($shipId, $this->driveLevel('combustion'), $this->driveLevel('impulse'), $this->driveLevel('hyperspace'));

        return $this->formatService->link('', (string) __('game/ships.' . $shipName), $title);
    }

    private function buildMaxShipsLink(int $shipId): ?string
    {
        if ($shipId === Ships::ship_solar_satellite) {
            return null;
        }

        return $this->formatService->link('#', (string) __('game/fleet.fl_max'), '', 'onclick="javascript:maxShip(\'ship' . $shipId . '\');"');
    }

    private function buildShipsInput(int $shipId): ?string
    {
        if ($shipId === Ships::ship_solar_satellite) {
            return null;
        }

        return '<input name="ship' . $shipId . '" size="10" value="0" onfocus="javascript:if(this.value == \'0\') this.value=\'\';" onblur="javascript:if(this.value == \'0\') this.value=\'\';"/>';
    }

    private function buildActionsBlock(): string
    {
        return $this->shipCount > 0 && $this->checkAvailableSlot()
            ? view('fleet.fleet1_selector_row')->render()
            : '';
    }

    private function buildNoShipsBlock(): string
    {
        return $this->shipCount <= 0 ? view('fleet.fleet1_noships_row')->render() : '';
    }

    private function buildContinueBlock(): string
    {
        return $this->shipCount > 0 && $this->checkAvailableSlot()
            ? view('fleet.fleet1_button')->render()
            : '';
    }

    private function checkAvailableSlot(): bool
    {
        return $this->fleetsService->getMaxFleets(
            $this->research->getCurrentResearch()->getResearchComputerTechnology(),
            $this->officerService->isOfficerActive($this->premium->getCurrentPremium()->getPremiumOfficierAdmiral(), time())
        ) > $this->fleets->getFleetsCount();
    }

    /**
     * @return array<string, int>
     */
    private function setInputsData(Request $request): array
    {
        // fresh wizard run: reset any half-finished selection carried in session
        session(['fleet_data' => []]);

        return [
            'galaxy' => $this->positionInput($request, 'galaxy', 1, MAX_GALAXY_IN_WORLD, $this->planetInt('planet_galaxy')),
            'system' => $this->positionInput($request, 'system', 1, MAX_SYSTEM_IN_GALAXY, $this->planetInt('planet_system')),
            'planet' => $this->positionInput($request, 'planet', 1, MAX_PLANET_IN_SYSTEM + 1, $this->planetInt('planet_planet')),
            'planettype' => $this->positionInput($request, 'planettype', 1, 3, $this->planetInt('planet_type')),
            'target_mission' => $request->has('target_mission') ? $request->integer('target_mission') : 0,
        ];
    }

    private function positionInput(Request $request, string $key, int $min, int $max, int $default): int
    {
        if (!$request->has($key)) {
            return $default;
        }

        $value = $request->integer($key);

        return $value >= $min && $value <= $max ? $value : $default;
    }

    private function driveLevel(string $drive): int
    {
        return $this->userInt('research_' . $drive . '_drive');
    }

    private function planetInt(string $key): int
    {
        $value = $this->planet[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
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
}
