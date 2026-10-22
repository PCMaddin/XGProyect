<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Enums\Module;
use App\Models\Preferences;
use App\Models\User;
use App\Services\FormatService;
use App\Services\Game\AccountUpdateService;
use App\Services\Game\PreferencesService;
use App\Services\Game\VacationModeService;
use App\Services\SettingsService;
use App\Services\TimingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use App\Libraries\Functions;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PreferencesController extends BaseController
{
    public function __construct(
        private FormatService $formatService,
        private TimingService $timingService,
        private SettingsService $settingsService,
        private PreferencesService $preferencesService,
        private VacationModeService $vacationMode,
        private AccountUpdateService $accountUpdate,
    ) {
    }

    public function __invoke(Request $request): View
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Options));

        $user = $request->user();

        if (!$user instanceof User) {
            abort(403);
        }

        $preferences = $this->preferencesService->preferencesFor($user);
        $submitted = $request->isMethod('post');
        $error = '';

        if ($submitted) {
            if ($request->has('preference_vacation_mode')) {
                $this->vacationMode->toggle($user, $preferences);
                $preferences->refresh();
            }

            $error = $this->accountUpdate->save($request, $user, $preferences, $this->preferencesService->formUpdates($request));
            $user->refresh();
            $preferences->refresh();
        }

        return view('preferences.view', $this->buildViewData($user, $preferences, $submitted, $error));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(User $user, Preferences $preferences, bool $submitted, string $error): array
    {
        return array_merge(
            [
                'gameTitle' => $this->settingsService->getString('game_name'),
                'two_factor' => $user->hasTwoFactor(),
            ],
            $this->messageData($submitted, $error),
            $this->userData($user, $preferences),
            [
                'preference_spy_probes' => $preferences->preference_spy_probes,
                'preference_fleet_escape' => $preferences->preference_fleet_escape !== 0,
                'sort_planet' => $this->preferencesService->planetSortOptions($preferences),
                'sort_sequence' => $this->preferencesService->planetSortSequenceOptions($preferences),
            ],
            $this->vacationModeData($user, $preferences),
            $this->deleteModeData($preferences)
        );
    }

    /**
     * @return array{color: string, message: string}
     */
    private function messageData(bool $submitted, string $error): array
    {
        if (!$submitted) {
            return [
                'color' => '',
                'message' => '',
            ];
        }

        return [
            'color' => $error === '' ? '#00ff00' : '#ff0000',
            'message' => $error === '' ? __('game/preferences.pr_ok_settings_saved') : $error,
        ];
    }

    /**
     * @return array{name: string, hide_nickname_change: string, email: string}
     */
    private function userData(User $user, Preferences $preferences): array
    {
        return [
            'name' => $user->name,
            'hide_nickname_change' => $this->preferencesService->isNicknameChangeAllowed($preferences) ? '' : 'style="display: none"',
            'email' => $user->email,
        ];
    }

    /**
     * @return array{hide_no_vacation: string, pr_vacation_mode_active: string, hide_vacation_invalid: string, disabled: string}
     */
    private function vacationModeData(User $user, Preferences $preferences): array
    {
        if ($this->vacationMode->isActive($preferences)) {
            return [
                'hide_no_vacation' => '',
                'pr_vacation_mode_active' => $this->formatService->strongText(
                    $this->formatService->colorRed(__('game/preferences.pr_vacation_mode_active'))
                ),
                'hide_vacation_invalid' => 'style="display: none"',
                'disabled' => $this->vacationMode->canBeRemoved($preferences) ? '' : 'style="display: none"',
            ];
        }

        if ($this->vacationMode->isEmpireActive($user)) {
            return [
                'hide_no_vacation' => '',
                'pr_vacation_mode_active' => $this->formatService->strongText(
                    $this->formatService->colorRed(__('game/preferences.pr_empire_active') . __('game/preferences.pr_empire_active_fleet'))
                ),
                'hide_vacation_invalid' => '',
                'disabled' => 'style="display: none"',
            ];
        }

        return [
            'hide_no_vacation' => 'style="display: none"',
            'pr_vacation_mode_active' => '',
            'hide_vacation_invalid' => '',
            'disabled' => '',
        ];
    }

    /**
     * @return array{pr_delete_account: string, preference_delete_mode: string, hide_delete: string}
     */
    private function deleteModeData(Preferences $preferences): array
    {
        if ((int) $preferences->preference_delete_mode > 0) {
            return [
                'pr_delete_account' => $this->formatService->colorRed(strtr(
                    $this->translation('game/preferences.pr_delete_mode_active'),
                    [
                        '%s' => $this->timingService->formatExtendedDate(
                            (int) $preferences->preference_delete_mode + ONE_WEEK
                        ),
                    ]
                )),
                'preference_delete_mode' => 'checked="checked"',
                'hide_delete' => 'style="display: none"',
            ];
        }

        return [
            'pr_delete_account' => $this->translation('game/preferences.pr_delete_account'),
            'preference_delete_mode' => '',
            'hide_delete' => '',
        ];
    }

    private function translation(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : '';
    }
}
