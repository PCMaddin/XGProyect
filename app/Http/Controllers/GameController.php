<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Game\GameBootstrap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * The game's front controller: game.php?page=… runs the GameBootstrap and
 * then the page's controller.
 */
class GameController extends BaseController
{
    /**
     * Game pages served by native controllers (after the GameBootstrap).
     *
     * @var array<string, class-string>
     */
    private const PAGES = [
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
        'items' => Game\ItemsController::class,
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
        'traderScrap' => Game\TraderScrapController::class,
        'traderImportExport' => Game\TraderImportExportController::class,
    ];

    public function __invoke(Request $request, GameBootstrap $bootstrap): BaseResponse
    {
        $file = strtr($request->getPathInfo(), ['/' => '', '.php' => '']);

        if ($file !== 'game') {
            return new Response('');
        }

        $page = $request->query('page');

        // unknown pages go to the overview
        if (!is_string($page) || !isset(self::PAGES[$page])) {
            return redirect('game.php?page=overview');
        }

        $user = $request->user();

        if ($user instanceof User) {
            $bootstrap->run($user);
        }

        $result = app()->call(self::PAGES[$page]);

        return $result instanceof BaseResponse ? $result : new Response($result);
    }
}
