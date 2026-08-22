<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\LegacyView;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use App\Libraries\UpdatesLibrary;

class LegacyController extends BaseController
{
    /**
     * Game pages promoted to Laravel controllers.
     * These bypass the legacy bootstrap entirely.
     *
     * @var array<string, class-string>
     */
    private const PROMOTED_PAGES = [
        'alliance' => Game\AllianceController::class,
        'banned' => Game\BannedController::class,
        'buddies' => Game\BuddiesController::class,
        'changelog' => Game\ChangelogController::class,
        'changenick' => Game\ChangenickController::class,
        'chat' => Game\ChatController::class,
        'combatreport' => Game\CombatreportController::class,
        'defenses' => Game\DefensesController::class,
        'empire' => Game\EmpireController::class,
        'facilities' => Game\FacilitiesController::class,
        'federationlayer' => Game\FederationController::class,
        'fleet1' => Game\Fleet1Controller::class,
        'fleet2' => Game\Fleet2Controller::class,
        'fleet3' => Game\Fleet3Controller::class,
        'fleet4' => Game\Fleet4Controller::class,
        'galaxy' => Game\GalaxyController::class,
        'highscore' => Game\HighscoreController::class,
        'logout' => Game\LogoutController::class,
        'messages' => Game\MessagesController::class,
        'movement' => Game\MovementController::class,
        'notices' => Game\NoticesController::class,
        'overview' => Game\OverviewController::class,
        'phalanx' => Game\PhalanxController::class,
        'planetlayer' => Game\PlanetlayerController::class,
        'playerprofile' => Game\PlayerprofileController::class,
        'preferences' => Game\PreferencesController::class,
        'premium' => Game\PremiumController::class,
        'research' => Game\ResearchController::class,
        'resourcesettings' => Game\ResourcesettingsController::class,
        'search' => Game\SearchController::class,
        'shipyard' => Game\ShipyardController::class,
        'shortcuts' => Game\FleetshortcutsController::class,
        'supplies' => Game\SuppliesController::class,
        'technologydetails' => Game\TechnologydetailsController::class,
        'technologytree' => Game\TechnologytreeController::class,
        'traderOverview' => Game\TraderOverviewController::class,
        'traderResources' => Game\TraderResourcesController::class,
    ];

    public function __invoke(Request $request): BaseResponse
    {
        $file = strtr($request->getPathInfo(), ['/' => '', '.php' => '']);

        if ($file === 'game') {
            $page = $request->query('page');

            if (is_string($page) && isset(self::PROMOTED_PAGES[$page])) {
                $this->runLegacyUpdates();

                $result = app()->call(self::PROMOTED_PAGES[$page]);

                return $result instanceof BaseResponse ? $result : new Response($result);
            }
        }

        try {
            ob_start();

            if (empty($file)) {
                $file = 'index';
            }

            if (in_array($file, ['game'])) {
                require app_path('Http') . '/' . $file . '.php';
            }

            $output = ob_get_clean();
        } catch (LegacyView $e) {
            $output = $e->getView();
        }

        return new Response($output);
    }

    /**
     * Run the per-request game updates that the legacy bootstrap (Common) runs
     * in setUpdates(). Promoted pages bypass that bootstrap, so without this the
     * fleet-arrival tick (and statistics/cleanup) would never fire on a session
     * that only visits native pages. The legacy fallthrough path still runs
     * Common itself, so this only covers the promoted branch (no double run).
     */
    private function runLegacyUpdates(): void
    {
        $settings = app(SettingsService::class);

        if (!defined('SHIP_DEBRIS_FACTOR')) {
            define('SHIP_DEBRIS_FACTOR', $settings->getInt('fleet_cdr') / 100);
        }

        if (!defined('DEFENSE_DEBRIS_FACTOR')) {
            define('DEFENSE_DEBRIS_FACTOR', $settings->getInt('defs_cdr') / 100);
        }

        app(UpdatesLibrary::class);
    }
}
