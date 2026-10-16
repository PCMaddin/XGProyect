<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Enums\PlayerClass;
use App\Libraries\Functions;
use App\Libraries\Users;
use App\Services\FormatService;
use App\Services\Game\PlayerClassService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Choose the player class (OGame 7): the first choice is free, a change
 * costs dark matter.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class CharacterClassController extends BaseController
{
    private const REDIRECT_TARGET = 'game.php?page=characterclass';

    /** @var array<int, list<string>> the bonuses shown per class, as language keys */
    private const BONUSES = [
        PlayerClass::Collector->value => [
            'cc_bonus_crawler', 'cc_bonus_mines', 'cc_bonus_energy', 'cc_bonus_cargo_speed',
            'cc_bonus_cargo_capacity', 'cc_bonus_crawler_more', 'cc_bonus_crawler_overload',
        ],
        PlayerClass::General->value => [
            'cc_bonus_reaper', 'cc_bonus_combat_speed', 'cc_bonus_recycler_speed', 'cc_bonus_fuel',
            'cc_bonus_recycler_cargo', 'cc_bonus_combat_tech', 'cc_bonus_fleet_slots', 'cc_bonus_moon_fields',
        ],
        PlayerClass::Discoverer->value => [
            'cc_bonus_pathfinder', 'cc_bonus_research', 'cc_bonus_expedition_finds', 'cc_bonus_planet_size',
            'cc_bonus_expeditions', 'cc_bonus_expedition_enemies', 'cc_bonus_phalanx', 'cc_bonus_inactive_loot',
        ],
    ];

    public function __construct(
        private PlayerClassService $classes,
        private FormatService $formatService,
    ) {
    }

    public function __invoke(Request $request): View | RedirectResponse
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Officers));

        $user = Users::getInstance()->getUserData();
        $userId = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
        $error = '';

        if ($request->isMethod('post')) {
            $result = $this->classes->choose($userId, PlayerClass::fromInt($request->integer('class')));

            if ($result === null) {
                return redirect(self::REDIRECT_TARGET)->with('class_success', (string) __('game/characterclass.cc_chosen'));
            }

            $error = (string) __('game/characterclass.' . $result);
        }

        $current = $this->classes->classOf($userId);
        $success = session('class_success');

        return view('characterclass.view', [
            'color' => $error !== '' ? '#ff0000' : (is_string($success) ? 'lime' : ''),
            'message' => $error !== '' ? $error : (is_string($success) ? $success : ''),
            'current' => $current->value,
            'price' => $current === PlayerClass::None
                ? (string) __('game/characterclass.cc_first_free')
                : sprintf((string) __('game/characterclass.cc_change_price'), $this->formatService->prettyNumber($this->classes->changePrice())),
            'classes' => array_map(fn (PlayerClass $class): array => [
                'value' => $class->value,
                'name' => (string) __('game/characterclass.' . $class->nameKey()),
                'bonuses' => array_map(fn (string $key): string => (string) __('game/characterclass.' . $key), self::BONUSES[$class->value]),
            ], [PlayerClass::Collector, PlayerClass::General, PlayerClass::Discoverer]),
        ]);
    }
}
