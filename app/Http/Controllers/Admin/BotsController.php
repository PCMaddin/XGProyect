<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\BotsRequest;
use App\Models\User;
use App\Services\Admin\BotService;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Bots module: populate the universe with bot players and switch their own
 * play on or off (what they do is {@see \App\Services\Game\Bots\BotPlayerService}).
 */
class BotsController extends BaseController
{
    public function __construct(
        private readonly BotService $bots,
        private readonly SettingsService $settings,
    ) {
    }

    public function index(): View
    {
        return view('admin.bots', [
            'max_galaxy' => MAX_GALAXY_IN_WORLD,
            'max_system' => MAX_SYSTEM_IN_GALAXY,
            'max_planet' => MAX_PLANET_IN_SYSTEM,
            'bots_play' => $this->settings->getBool('bots_play'),
            'bots_count' => User::where('user_is_bot', true)->count(),
        ]);
    }

    public function play(Request $request): RedirectResponse
    {
        $this->settings->write('bots_play', $request->boolean('bots_play') ? 1 : 0);
        session()->flash('success', __('admin/bots.bo_play_saved'));

        return redirect()->route('admin.bots');
    }

    public function generate(BotsRequest $request): RedirectResponse
    {
        $amount = $request->integer('amount');
        $galaxyFrom = max(1, $request->filled('galaxy_from') ? $request->integer('galaxy_from') : 1);
        $galaxyTo = min(MAX_GALAXY_IN_WORLD, $request->filled('galaxy_to') ? $request->integer('galaxy_to') : MAX_GALAXY_IN_WORLD);

        if ($galaxyTo < $galaxyFrom) {
            $galaxyTo = $galaxyFrom;
        }

        $created = $this->bots->createBots($amount, $galaxyFrom, $galaxyTo);

        if ($created === 0) {
            session()->flash('danger', __('admin/bots.bo_none'));

            return redirect()->route('admin.bots');
        }

        if ($created < $amount) {
            session()->flash('warning', __('admin/bots.bo_partial', ['count' => $created, 'requested' => $amount]));

            return redirect()->route('admin.bots');
        }

        session()->flash('success', trans_choice('admin/bots.bo_created', $created, ['count' => $created]));

        return redirect()->route('admin.bots');
    }
}
