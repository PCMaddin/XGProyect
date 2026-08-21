<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use App\Services\Game\Formulas\DevelopmentsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\BuildingsEnumerator as Buildings;
use Xgp\App\Core\Enumerators\DefensesEnumerator as Defenses;
use Xgp\App\Core\Enumerators\ShipsEnumerator as Ships;
use Xgp\App\Core\Objects;
use App\Libraries\DevelopmentsLib;
use App\Services\Game\Formulas\FormulasService;
use App\Libraries\Functions;
use App\Libraries\Users;

/**
 * Shipyard: build ships (and, via the Defenses subclass, defences/missiles).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 */
class ShipyardController extends BaseController
{
    use PreparesLegacySql;

    protected string $page = 'shipyard';

    protected string $langFile = 'ships';

    /** @var array<int, int> */
    protected array $allowedStructures = [
        Ships::ship_small_cargo_ship,
        Ships::ship_big_cargo_ship,
        Ships::ship_light_fighter,
        Ships::ship_heavy_fighter,
        Ships::ship_cruiser,
        Ships::ship_battleship,
        Ships::ship_colony_ship,
        Ships::ship_recycler,
        Ships::ship_espionage_probe,
        Ships::ship_bomber,
        Ships::ship_solar_satellite,
        Ships::ship_destroyer,
        Ships::ship_deathstar,
        Ships::ship_reaper,
    ];

    /** @var array<int, int> */
    protected array $missiles = [];

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    /** @var array<string, float> */
    private array $resourcesConsumed = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];

    private bool $buildingInProgress = false;

    private Objects $objects;

    private Users $userLibrary;

    public function __construct(
        private FormatService $formatService,
        private DevelopmentsService $developmentsService,
        private FormulasService $formulasService,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Shipyard));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();
        $this->objects = new Objects();
        $this->userLibrary = new Users();

        $this->setUpShipyard();

        $redirect = $this->runAction($request);

        if ($redirect !== null) {
            return $redirect;
        }

        return view('shipyard.view', [
            'message' => $this->buildingInProgress ? $this->formatService->colorRed((string) __('game/shipyard.sy_building_shipyard')) : '',
            'list_of_items' => $this->buildListOfItems(),
            'build_button' => $this->getBuildItemsButton(),
            'building_list' => $this->buildItemsQueue(),
        ]);
    }

    private function setUpShipyard(): void
    {
        if ($this->planetInt($this->objectName(21)) === 0) {
            Functions::message((string) __('game/shipyard.sy_shipyard_required'));
        }

        $this->setAllowedStructures();
        $this->detectFacilityInProgress();
    }

    private function runAction(Request $request): ?RedirectResponse
    {
        $items = $request->input('fmenge');

        if (!is_array($items)) {
            return null;
        }

        $this->resourcesConsumed = [
            'metal' => (float) $this->planetInt('planet_metal'),
            'crystal' => (float) $this->planetInt('planet_crystal'),
            'deuterium' => (float) $this->planetInt('planet_deuterium'),
        ];

        $queue = '';
        $total = 0;

        foreach ($items as $item => $amount) {
            $itemId = $this->asInt($item);
            $requested = $this->asInt($amount);

            if (!in_array($itemId, $this->allowedStructures, true) || $requested <= 0 || $this->isShieldDomeAvailable($itemId)) {
                continue;
            }

            $buildable = $this->getMaxBuildableItems($itemId, $requested);

            if ($buildable <= 0) {
                continue;
            }

            $needed = $this->getItemNeededResourcesByAmount($itemId, $buildable);
            $this->resourcesConsumed['metal'] -= $needed['metal'];
            $this->resourcesConsumed['crystal'] -= $needed['crystal'];
            $this->resourcesConsumed['deuterium'] -= $needed['deuterium'];
            $queue .= $itemId . ',' . $buildable . ';';
            $total += $buildable;
        }

        if ($total > 0) {
            DB::update(
                $this->prepareSql(
                    'UPDATE `' . PLANETS . '` AS p SET
                        p.`planet_b_hangar_id` = CONCAT(p.`planet_b_hangar_id`, ?),
                        p.`planet_metal` = ?, p.`planet_crystal` = ?, p.`planet_deuterium` = ?
                    WHERE p.`planet_id` = ?;'
                ),
                [
                    $queue,
                    $this->resourcesConsumed['metal'],
                    $this->resourcesConsumed['crystal'],
                    $this->resourcesConsumed['deuterium'],
                    $this->planetInt('planet_id'),
                ]
            );
        }

        return redirect('game.php?page=' . $this->page);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildListOfItems(): array
    {
        return array_map(fn (int $itemId): array => [
            'element' => $itemId,
            'element_name' => __('game/' . $this->langFile . '.' . $this->objectName($itemId)),
            'element_description' => $this->getItemDescription($itemId),
            'element_price' => DevelopmentsLib::formatedDevelopmentPrice($this->user, $this->planet, $itemId, false),
            'building_time' => DevelopmentsLib::formatedDevelopmentTime($this->getItemTime($itemId), (string) __('game/shipyard.sy_time')),
            'element_nbre' => $this->getItemAmountWithFormat($itemId),
            'add_element' => $this->getItemInsertBlock($itemId),
        ], array_values($this->allowedStructures));
    }

    private function getItemDescription(int $itemId): string
    {
        $description = $this->descriptionText($this->objectName($itemId));

        if ($itemId === Defenses::defense_interplanetary_missile) {
            return strtr($description, ['%s' => (string) $this->formulasService->missileRange($this->userInt('research_impulse_drive'))]);
        }

        return $description;
    }

    private function getItemTime(int $itemId): int
    {
        return $this->developmentsService->developmentTime(
            $itemId,
            0,
            $this->planetInt($this->objectName(Buildings::BUILDING_HANGAR)),
            $this->planetInt($this->objectName(Buildings::BUILDING_NANO_FACTORY)),
            0,
            0,
            false
        );
    }

    private function getItemAmountWithFormat(int $itemId): string
    {
        $amount = $this->getItemAmount($itemId);

        return $amount === 0
            ? ''
            : ' (' . __('game/shipyard.sy_available') . $this->formatService->prettyNumber($amount) . ')';
    }

    private function getItemInsertBlock(int $itemId): string
    {
        if ($this->buildingInProgress || $this->userLibrary->isOnVacations($this->user)) {
            return '';
        }

        if ($this->isShieldDomeAvailable($itemId)) {
            return $this->formatService->colorRed((string) __('game/shipyard.sy_protection_shield_only_one'));
        }

        return view('shipyard.shipyard_build_box', ['item_id' => $itemId, 'tab_index' => $itemId])->render();
    }

    private function getItemAmount(int $itemId): int
    {
        return $this->planetInt($this->objectMapName($itemId));
    }

    private function getBuildItemsButton(): string
    {
        if ($this->buildingInProgress || $this->userLibrary->isOnVacations($this->user)) {
            return '';
        }

        return view('shipyard.shipyard_build_button')->render();
    }

    private function buildItemsQueue(): string
    {
        $queue = explode(';', $this->planetStr('planet_b_hangar_id'));

        if ($queue[0] === '') {
            return '';
        }

        $queueTime = 0;
        $times = '';
        $names = '';
        $amounts = '';

        foreach ($queue as $entry) {
            if ($entry === '') {
                continue;
            }

            [$rawId, $rawAmount] = array_pad(explode(',', $entry), 2, '0');
            $id = (int) $rawId;
            $amount = (int) $rawAmount;
            $itemTime = $this->getItemTime($id);
            $type = str_contains($this->objectName($id), 'ship') ? 'ships' : 'defenses';

            $times .= $itemTime . ',';
            $names .= '\'' . html_entity_decode((string) __('game/' . $type . '.' . $this->objectName($id)), ENT_COMPAT, 'utf-8') . '\',';
            $amounts .= $amount . ',';
            $queueTime += $itemTime * $amount;
        }

        return view('shipyard.shipyard_script', [
            'a' => $amounts,
            'b' => $names,
            'c' => $times,
            'b_hangar_id_plus' => $this->planetInt('planet_b_hangar'),
            'current_page' => $this->page,
            'pretty_time_b_hangar' => $this->formatService->prettyTime((float) ($queueTime - $this->planetInt('planet_b_hangar'))),
        ])->render();
    }

    private function setAllowedStructures(): void
    {
        $levels = [];

        foreach ($this->objectMap() as $id => $column) {
            $levels[$id] = $this->planetInt($column) !== 0 ? $this->planetInt($column) : $this->userInt($column);
        }

        $this->allowedStructures = array_filter(
            $this->allowedStructures,
            fn (int $value): bool => $this->developmentsService->isDevelopmentAllowed($value, $levels)
        );
    }

    private function detectFacilityInProgress(): void
    {
        $this->buildingInProgress = false;

        if ($this->planetInt('planet_b_building_id') === 0) {
            return;
        }

        foreach (explode(';', $this->planetStr('planet_b_building_id')) as $entry) {
            $building = (int) explode(',', $entry)[0];

            if (in_array($building, [14, 15, 21], true)) {
                $this->buildingInProgress = true;

                return;
            }
        }
    }

    private function getMaxBuildableItems(int $itemId, int $requested): int
    {
        if (in_array($itemId, [Defenses::defense_small_shield_dome, Defenses::defense_large_shield_dome], true)) {
            $requested = min($requested, $this->getShieldDomeItemLimit($itemId));
        }

        if ($this->isMissile($itemId)) {
            $requested = min($requested, $this->getMissilesItemLimit($itemId));
        }

        $requested = min($requested, $this->getMaxBuildableItemsByResource($itemId), MAX_FLEET_OR_DEFS_PER_ROW);

        if ($this->isMissile($itemId)) {
            $this->missiles[$itemId] = ($this->missiles[$itemId] ?? 0) + $requested;
        }

        return $requested;
    }

    private function getMaxBuildableItemsByResource(int $itemId): int
    {
        $buildable = [];

        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $price = $this->price($itemId, $resource);

            if ($price !== 0) {
                $buildable[] = (int) floor($this->resourcesConsumed[$resource] / $price);
            }
        }

        return $buildable === [] ? 0 : max(min($buildable), 0);
    }

    private function getShieldDomeItemLimit(int $itemId): int
    {
        return $this->isShieldDomeAvailable($itemId) ? 0 : 1;
    }

    private function getMissilesItemLimit(int $itemId): int
    {
        $this->calculateMissilesAmount();

        $siloSize = $this->planetInt($this->objectName(44)) * 10;
        $takenSpace = ($this->missiles[Defenses::defense_anti_ballistic_missile] ?? 0)
            + (($this->missiles[Defenses::defense_interplanetary_missile] ?? 0) * 2);
        $available = $siloSize - $takenSpace;

        if ($itemId === Defenses::defense_interplanetary_missile) {
            return (int) floor($available / 2);
        }

        return $available;
    }

    /**
     * @return array<string, float>
     */
    private function getItemNeededResourcesByAmount(int $itemId, int $amount): array
    {
        return [
            'metal' => (float) ($this->price($itemId, 'metal') * $amount),
            'crystal' => (float) ($this->price($itemId, 'crystal') * $amount),
            'deuterium' => (float) ($this->price($itemId, 'deuterium') * $amount),
        ];
    }

    private function isShieldDomeAvailable(int $itemId): bool
    {
        if (!in_array($itemId, [Defenses::defense_small_shield_dome, Defenses::defense_large_shield_dome], true)) {
            return false;
        }

        return $this->planetInt($this->objectName($itemId)) >= 1
            || str_contains($this->planetStr('planet_b_hangar_id'), $itemId . ',');
    }

    private function calculateMissilesAmount(): void
    {
        $queue = $this->processQueueToArray();

        foreach ([Defenses::defense_anti_ballistic_missile, Defenses::defense_interplanetary_missile] as $missile) {
            $this->missiles[$missile] = ($this->missiles[$missile] ?? 0)
                + $this->planetInt($this->objectName($missile))
                + ($queue[$missile] ?? 0);
        }
    }

    /**
     * @return array<int, int>
     */
    private function processQueueToArray(): array
    {
        $result = [];

        foreach (explode(';', $this->planetStr('planet_b_hangar_id')) as $entry) {
            if ($entry === '') {
                continue;
            }

            [$rawId, $rawAmount] = array_pad(explode(',', $entry), 2, '0');
            $result[(int) $rawId] = ($result[(int) $rawId] ?? 0) + (int) $rawAmount;
        }

        return $result;
    }

    private function isMissile(int $itemId): bool
    {
        return in_array($itemId, [Defenses::defense_anti_ballistic_missile, Defenses::defense_interplanetary_missile], true);
    }

    private function descriptionText(string $key): string
    {
        $descriptions = trans('game/shipyard.descriptions');

        if (is_array($descriptions) && isset($descriptions[$key]) && is_scalar($descriptions[$key])) {
            return (string) $descriptions[$key];
        }

        return '';
    }

    private function price(int $itemId, string $resource): int
    {
        $price = $this->objects->getPrice($itemId, $resource);

        return is_numeric($price) ? (int) $price : 0;
    }

    private function objectName(int $itemId): string
    {
        $name = $this->objects->getObjects($itemId);

        return is_string($name) ? $name : '';
    }

    private function objectMapName(int $itemId): string
    {
        return $this->asString($this->objectMap()[$itemId] ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function objectMap(): array
    {
        $map = $this->objects->getObjects();

        if (!is_array($map)) {
            return [];
        }

        $result = [];

        foreach ($map as $id => $column) {
            if (is_numeric($id) && is_scalar($column)) {
                $result[(int) $id] = (string) $column;
            }
        }

        return $result;
    }

    private function planetInt(string $key): int
    {
        $value = $this->planet[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function planetStr(string $key): string
    {
        $value = $this->planet[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
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
