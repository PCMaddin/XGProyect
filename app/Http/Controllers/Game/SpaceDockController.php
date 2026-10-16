<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Core\GameObjects\GameObjectRegistry;
use App\Enums\Module;
use App\Libraries\Functions;
use App\Libraries\Users;
use App\Services\FormatService;
use App\Services\Game\WreckFieldService;
use App\Services\TimingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * The space dock (OGame 7): the wreck field of the current planet, its
 * repair and putting the repaired ships back into service.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class SpaceDockController extends BaseController
{
    private const REDIRECT_TARGET = 'game.php?page=spacedock';

    public function __construct(
        private WreckFieldService $wrecks,
        private FormatService $formatService,
        private TimingService $timingService,
        private GameObjectRegistry $registry,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Buildings));

        $user = Users::getInstance()->getUserData();
        $planet = Users::getInstance()->getPlanetData();
        $userId = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
        $planetId = is_numeric($planet['planet_id'] ?? null) ? (int) $planet['planet_id'] : 0;
        $now = time();

        if ($request->isMethod('post')) {
            return $this->handleAction($request->input('action') === 'deploy', $userId, $planetId, $now);
        }

        $this->wrecks->settle($planetId, $now);
        $field = $this->wrecks->field($planetId);
        $dock = is_numeric($planet['building_space_dock'] ?? null) ? (int) $planet['building_space_dock'] : 0;

        return view('spacedock.view', $this->notice() + [
            'dock' => $dock,
            'share' => $this->formatService->prettyNumber((int) round($this->wrecks->repairShare($dock) * 100)),
            'field' => $field === null ? null : $this->fieldData($field, $dock, $now),
        ]);
    }

    private function handleAction(bool $deploy, int $userId, int $planetId, int $now): RedirectResponse
    {
        $error = $deploy
            ? $this->wrecks->deploy($userId, $planetId, $now)
            : $this->wrecks->startRepair($userId, $planetId, $now);

        return redirect(self::REDIRECT_TARGET)->with(
            $error === null ? 'spacedock_success' : 'spacedock_error',
            (string) __('game/spacedock.' . ($error ?? ($deploy ? 'sd_deployed' : 'sd_repair_started')))
        );
    }

    /**
     * @return array{color: string, message: string}
     */
    private function notice(): array
    {
        $error = session('spacedock_error');
        $success = session('spacedock_success');

        if (is_string($error)) {
            return ['color' => '#ff0000', 'message' => $error];
        }

        return is_string($success) ? ['color' => 'lime', 'message' => $success] : ['color' => '', 'message' => ''];
    }

    /**
     * @param array<int, int> $ships
     *
     * @return list<array{name: string, wrecks: string}>
     */
    private function wreckRows(array $ships): array
    {
        $rows = [];

        foreach ($ships as $id => $amount) {
            $rows[] = ['name' => $this->shipName($id), 'wrecks' => $this->formatService->prettyNumber($amount)];
        }

        return $rows;
    }

    private function shipName(int $id): string
    {
        return (string) __('game/ships.' . $this->registry->get($id)->getName());
    }

    /**
     * @param array{planet_id: int, user_id: int, ships: array<int, int>, created_at: int, expires_at: int, repairable_from: int, repair_started_at: int, repair_ends_at: int, repaired: array<int, int>} $field
     *
     * @return array<string, mixed>
     */
    private function fieldData(array $field, int $dock, int $now): array
    {
        $repairing = $field['repair_started_at'] > 0;
        $ships = $repairing ? $field['repaired'] : $this->wrecks->repairable($field['ships'], $dock);
        $soFar = $this->wrecks->repairedSoFar($field, $now);
        $rows = [];

        // in a repair the wrecks are used up; the ones of later battles wait
        foreach (array_keys($repairing ? $field['repaired'] : $field['ships']) as $id) {
            $rows[] = [
                'name' => $this->shipName($id),
                'wrecks' => $this->formatService->prettyNumber($field['ships'][$id] ?? 0),
                'repaired' => $this->formatService->prettyNumber($ships[$id] ?? 0),
                'so_far' => $this->formatService->prettyNumber($soFar[$id] ?? 0),
            ];
        }

        return [
            'rows' => $rows,
            'new_wrecks' => $repairing && $field['expires_at'] > $now ? $this->wreckRows($field['ships']) : [],
            'repairing' => $repairing,
            'done' => $repairing && $field['repair_ends_at'] <= $now,
            'early' => $this->wrecks->canDeployEarly($field, $now),
            'early_from' => $this->timingService->formatExtendedDate($field['repair_started_at'] + WreckFieldService::MIN_REPAIR_TIME),
            'waiting' => !$repairing && $field['repairable_from'] > $now,
            'repairable_from' => $this->timingService->formatExtendedDate($field['repairable_from']),
            'expires' => $this->timingService->formatExtendedDate($field['expires_at']),
            'ends' => $this->timingService->formatExtendedDate($field['repair_ends_at']),
            'deploy_by' => $this->timingService->formatExtendedDate($field['repair_ends_at'] + WreckFieldService::LIFETIME),
            'can_repair' => !$repairing && $dock > 0 && $ships !== [] && $field['repairable_from'] <= $now,
        ];
    }
}
