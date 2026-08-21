<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller as BaseController;
use App\Libraries\Functions;

/**
 * Trader landing page. The resource market is reached from here.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class TraderOverviewController extends BaseController
{
    public function __invoke(): View
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Trader));

        return view('trader.overview', [
            'color' => '',
            'message' => '',
            'currentMode' => '',
        ]);
    }
}
