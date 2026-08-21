<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Models\Planets;
use App\Services\FormatService;
use App\Services\Game\HomePlanetService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator;
use App\Libraries\Functions;
use Xgp\App\Libraries\Users;

/**
 * Planet detail layer: rename or abandon the current planet/moon.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PlanetlayerController extends BaseController
{
    use PreparesLegacySql;

    private const REDIRECT_TARGET = 'game.php?page=planetlayer';

    /** @var array<string, mixed> */
    private array $user = [];

    /** @var array<string, mixed> */
    private array $planet = [];

    public function __construct(
        private FormatService $formatService,
        private HomePlanetService $homePlanetService,
    ) {
    }

    public function __invoke(Request $request): View
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Overview));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();

        return $this->buildPage($request);
    }

    private function buildPage(Request $request): View
    {
        $hasColonies = Planets::where([
            'planet_user_id' => $this->int($this->user, 'id'),
            'planet_type' => PlanetTypesEnumerator::PLANET,
            'planet_destroyed' => 0,
        ])->count() > 1;

        $isMoon = $this->int($this->planet, 'planet_type') === PlanetTypesEnumerator::MOON;

        if ($request->has('planetName')) {
            $this->renamePlanet($request);
        }

        if ($request->has('password') && $hasColonies) {
            $this->deletePlanet($request);
        }

        return view('planetlayer.view', [
            'planetImage' => $this->str($this->planet, 'planet_image'),
            'mainPlanet' => $this->int($this->user, 'home_planet_id') === $this->int($this->planet, 'planet_id'),
            'withColonies' => $hasColonies,
            'isMoon' => $isMoon,
            'defaultName' => $isMoon
                ? __('game/planetlayer.new_moon_name')
                : __('game/planetlayer.new_planet_name'),
            'planetCoords' => $this->formatService->formatCoords(
                $this->int($this->planet, 'planet_galaxy'),
                $this->int($this->planet, 'planet_system'),
                $this->int($this->planet, 'planet_planet')
            ),
            'planetName' => $this->str($this->planet, 'planet_name'),
        ]);
    }

    private function renamePlanet(Request $request): void
    {
        $newName = strip_tags(trim(is_string($raw = $request->input('planetName')) ? $raw : ''));

        if (preg_match('/[^A-z0-9_\- ]/', $newName) === 1) {
            Functions::popupMessage(__('game/planetlayer.rename_error'), self::REDIRECT_TARGET, 3);
        }

        if ($newName === '') {
            return;
        }

        DB::update(
            $this->prepareSql('UPDATE `' . PLANETS . '` SET `planet_name` = ? WHERE `planet_id` = ? LIMIT 1;'),
            [$newName, $this->int($this->user, 'current_planet')]
        );

        Functions::popupMessage(
            __('game/planetlayer.rename_success', ['name' => $newName]),
            self::REDIRECT_TARGET,
            3
        );
    }

    private function deletePlanet(Request $request): void
    {
        $this->guardAgainstActiveFleets();

        $password = is_string($raw = $request->input('password')) ? $raw : '';

        if (!Hash::check($password, $this->str($this->user, 'password'))) {
            Functions::popupMessage(__('game/planetlayer.wrong_password'), self::REDIRECT_TARGET, 3);

            return;
        }

        $destroyAt = time() + (PLANETS_LIFE_TIME * 3600);
        $currentPlanet = $this->int($this->user, 'current_planet');
        $userId = $this->int($this->user, 'id');

        if ($this->int($this->planet, 'moon_id') !== 0) {
            DB::update(
                $this->prepareSql(
                    'UPDATE `' . PLANETS . '` AS p, `' . PLANETS . '` AS m, `' . USERS . '` AS u SET
                        p.`planet_destroyed` = ?,
                        m.`planet_destroyed` = ?,
                        u.`current_planet` = u.`home_planet_id`
                    WHERE p.`planet_id` = ?
                        AND m.`planet_galaxy` = ?
                        AND m.`planet_system` = ?
                        AND m.`planet_planet` = ?
                        AND m.`planet_type` = ?
                        AND u.`id` = ?;'
                ),
                [
                    $destroyAt,
                    $destroyAt,
                    $currentPlanet,
                    $this->int($this->planet, 'planet_galaxy'),
                    $this->int($this->planet, 'planet_system'),
                    $this->int($this->planet, 'planet_planet'),
                    PlanetTypesEnumerator::MOON,
                    $userId,
                ]
            );
        }

        if ($this->int($this->planet, 'moon_id') === 0) {
            DB::update(
                $this->prepareSql(
                    'UPDATE `' . PLANETS . '` AS p, `' . USERS . '` AS u SET
                        p.`planet_destroyed` = ?,
                        u.`current_planet` = u.`home_planet_id`
                    WHERE p.`planet_id` = ? AND u.`id` = ?;'
                ),
                [$destroyAt, $currentPlanet, $userId]
            );
        }

        $this->homePlanetService->moveCurrentAfterAbandoningPlanet(
            $userId,
            $currentPlanet,
            $this->int($this->user, 'home_planet_id')
        );

        Functions::popupMessage(__('game/planetlayer.rp_planet_abandoned'), self::REDIRECT_TARGET, 3);
    }

    private function guardAgainstActiveFleets(): void
    {
        $userId = $this->int($this->user, 'id');

        $fleets = array_map(
            fn (object $row): array => (array) $row,
            DB::select(
                $this->prepareSql(
                    'SELECT `fleet_owner`, `fleet_target_owner`, `fleet_end_type`, `fleet_mess`
                    FROM `' . FLEETS . '`
                    WHERE (
                        `fleet_owner` = ?
                        AND `fleet_start_galaxy` = ?
                        AND `fleet_start_system` = ?
                        AND `fleet_start_planet` = ?
                    ) OR (
                        `fleet_target_owner` = ?
                        AND `fleet_end_galaxy` = ?
                        AND `fleet_end_system` = ?
                        AND `fleet_end_planet` = ?
                    );'
                ),
                [
                    $userId,
                    $this->int($this->planet, 'planet_galaxy'),
                    $this->int($this->planet, 'planet_system'),
                    $this->int($this->planet, 'planet_planet'),
                    $userId,
                    $this->int($this->planet, 'planet_galaxy'),
                    $this->int($this->planet, 'planet_system'),
                    $this->int($this->planet, 'planet_planet'),
                ]
            )
        );

        $endType = 0;

        foreach ($fleets as $fleet) {
            $ownFleet = $this->int($fleet, 'fleet_owner');
            $enemyFleet = $this->int($fleet, 'fleet_target_owner');

            if ($enemyFleet === $userId) {
                $endType = $this->int($fleet, 'fleet_end_type');
            }

            if ($this->fleetBlocksAbandon($ownFleet, $enemyFleet, $this->int($fleet, 'fleet_mess'), $endType)) {
                Functions::popupMessage(
                    __('game/planetlayer.rp_abandon_planet_not_possible'),
                    self::REDIRECT_TARGET,
                    3
                );
            }
        }
    }

    /**
     * A planet cannot be abandoned while the player has a fleet departing from it,
     * or an unread hostile fleet inbound that is not a recall.
     */
    private function fleetBlocksAbandon(int $ownFleet, int $enemyFleet, int $mess, int $endType): bool
    {
        if ($ownFleet > 0) {
            return true;
        }

        return $enemyFleet > 0 && $mess < 1 && $endType !== 2;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
