<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ResetRequest;
use App\Services\Admin\ResetService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\View\View;

class ResetController extends BaseController
{
    public function __construct(private readonly ResetService $resetService)
    {
    }

    public function index(): View
    {
        return view('admin.reset');
    }

    public function reset(ResetRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (isset($data['resetall'])) {
            $this->resetService->resetAll();

            return redirect()->route('admin.reset')->with('success', __('admin/reset.re_reset_excess'));
        }

        foreach ($this->resets() as $field => $reset) {
            if (isset($data[$field])) {
                $reset();
            }
        }

        return redirect()->route('admin.reset')->with('success', __('admin/reset.re_reset_excess'));
    }

    /**
     * The partial resets by form field, in the order they run.
     *
     * @return array<string, Closure(): void>
     */
    private function resets(): array
    {
        $service = $this->resetService;

        return [
            'defenses' => $service->resetDefenses(...),
            'ships' => $service->resetShips(...),
            'h_d' => $service->resetShipyardQueues(...),
            'edif_p' => $service->resetPlanetBuildings(...),
            'edif_l' => $service->resetMoonBuildings(...),
            'edif' => $service->resetBuildingsQueues(...),
            'inves' => $service->resetResearch(...),
            'inves_c' => $service->resetResearchQueues(...),
            'ofis' => $service->resetOfficiers(...),
            'dark' => $service->resetDarkMatter(...),
            'resources' => $service->resetResources(...),
            'notes' => $service->resetNotes(...),
            'rw' => $service->resetReports(...),
            'friends' => $service->resetFriends(...),
            'alliances' => $service->resetAlliances(...),
            'fleets' => $service->resetFleets(...),
            'banneds' => $service->resetBanned(...),
            'messages' => $service->resetMessages(...),
            'statpoints' => $service->resetStatistics(...),
            'moons' => $service->resetMoons(...),
        ];
    }
}
