<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Services\FormatService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\Users;
use App\Libraries\Users\Shortcuts;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class FleetshortcutsController extends BaseController
{
    use PreparesLegacySql;

    public const REDIRECT_TARGET = 'game.php?page=shortcuts';

    private const MODES = ['add', 'edit', 'delete'];

    /** @var array<string, mixed> */
    private array $user = [];

    private Shortcuts $shortcuts;

    public function __construct(private FormatService $formatService)
    {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Fleet));

        $this->user = Users::getInstance()->getUserData();
        $this->shortcuts = new Shortcuts($this->str($this->user, 'fleet_shortcuts'));

        $mode = $this->resolveMode(is_string($raw = $request->query('mode')) ? $raw : null);

        if ($mode !== null && $request->isMethod('post')) {
            return $this->persist($request, $mode);
        }

        return match ($mode) {
            'add' => $this->addForm(),
            'edit' => $this->editForm($request),
            default => $this->listView(),
        };
    }

    /**
     * Only the modes backed by a real handler are accepted. The legacy code also
     * whitelisted "a", which dispatched to a non-existent aShortcut() method.
     */
    private function resolveMode(?string $mode): ?string
    {
        return in_array($mode, self::MODES, true) ? $mode : null;
    }

    private function persist(Request $request, string $mode): RedirectResponse
    {
        $name = trim(is_string($raw = $request->input('name')) ? $raw : '');
        $galaxy = $request->integer('galaxy');
        $system = $request->integer('system');
        $planet = $request->integer('planet');
        $type = $request->integer('type');
        $action = $request->has('a') ? $request->integer('a') : null;

        $valid = $name !== ''
            && $this->inRange($galaxy, 1, MAX_GALAXY_IN_WORLD)
            && $this->inRange($system, 1, MAX_SYSTEM_IN_GALAXY)
            && $this->inRange($planet, 1, MAX_PLANET_IN_SYSTEM + 1)
            && $this->inRange($type, 1, 3);

        if ($valid) {
            if ($mode === 'edit' && $action !== null) {
                $this->shortcuts->editById($action, $name, $galaxy, $system, $planet, $type);
            } elseif ($mode === 'delete' && $action !== null) {
                $this->shortcuts->deleteById($action);
            } else {
                $this->shortcuts->addNew($name, $galaxy, $system, $planet, $type);
            }

            // Persist the shortcut collection with a bound parameter. The legacy
            // code concatenated the JSON string straight into the query, which
            // broke (and was injectable) on names containing quotes.
            DB::update(
                $this->prepareSql('UPDATE `' . USERS . '` SET `fleet_shortcuts` = ? WHERE `id` = ?;'),
                [$this->shortcuts->getAllAsJsonString(), $this->int($this->user, 'id')]
            );
        }

        return redirect(self::REDIRECT_TARGET);
    }

    private function addForm(): View
    {
        return view('fleet.shortcuts.edit', [
            'mode' => 'add',
            'visibility' => 'hidden',
            'shortcut_id' => '',
            'name' => '',
            'galaxy' => '',
            'system' => '',
            'planet' => '',
            'planetTypes' => $this->planetTypeOptions(),
        ]);
    }

    private function editForm(Request $request): View | RedirectResponse
    {
        $action = $request->has('a') ? $request->integer('a') : null;
        $shortcuts = $this->shortcuts->getAllAsArray();

        if ($action === null || !array_key_exists($action, $shortcuts)) {
            return redirect(self::REDIRECT_TARGET);
        }

        $shortcut = $shortcuts[$action];

        return view('fleet.shortcuts.edit', [
            'mode' => 'edit',
            'visibility' => 'button',
            'shortcut_id' => '&a=' . $action,
            'name' => $this->str($shortcut, 'name'),
            'galaxy' => $this->int($shortcut, 'g'),
            'system' => $this->int($shortcut, 's'),
            'planet' => $this->int($shortcut, 'p'),
            'planetTypes' => $this->planetTypeOptions($this->int($shortcut, 'pt')),
        ]);
    }

    private function listView(): View
    {
        return view('fleet.shortcuts.view', [
            'shortcuts' => $this->buildShortcutList(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildShortcutList(): array
    {
        $list = [];
        $setRow = true;

        foreach ($this->shortcuts->getAllAsArray() as $id => $shortcut) {
            $list[] = [
                'row_start' => $setRow ? '<tr height="20">' : '',
                'shortcut_id' => $id,
                'shortcut_name' => $this->str($shortcut, 'name'),
                'shortcut_coords' => $this->formatService->formatCoords(
                    $this->int($shortcut, 'g'),
                    $this->int($shortcut, 's'),
                    $this->int($shortcut, 'p'),
                ),
                'shortcut_type' => $this->planetTypeShort($this->int($shortcut, 'pt')),
                'row_end' => !$setRow ? '</tr>' : '',
            ];

            $setRow = !$setRow;
        }

        return $list;
    }

    /**
     * @return array<int, array{selected: string, value: int, name: string}>
     */
    private function planetTypeOptions(int $selected = 0): array
    {
        $types = [
            PlanetTypesEnumerator::PLANET => 'fl_planet',
            PlanetTypesEnumerator::DEBRIS => 'fl_debris',
            PlanetTypesEnumerator::MOON => 'fl_moon',
        ];

        $options = [];

        foreach ($types as $id => $name) {
            $options[] = [
                'selected' => $id === $selected ? ' selected="selected" ' : '',
                'value' => $id,
                'name' => (string) __('game/fleet.' . $name),
            ];
        }

        return $options;
    }

    private function planetTypeShort(int $planetType): string
    {
        $labels = trans('game/global.planet_type_short');

        if (is_array($labels) && isset($labels[$planetType]) && is_scalar($labels[$planetType])) {
            return (string) $labels[$planetType];
        }

        return '';
    }

    private function inRange(int $value, int $min, int $max): bool
    {
        return $value >= $min && $value <= $max;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
