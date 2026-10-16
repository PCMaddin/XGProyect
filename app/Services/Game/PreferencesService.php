<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Models\Preferences;
use App\Models\User;
use Illuminate\Http\Request;
use App\Core\Enumerators\PreferencesEnumerator as PrefEnum;

class PreferencesService
{
    public function __construct(
        private Preferences $preferences,
    ) {
    }

    public function preferencesFor(User $user): Preferences
    {
        /** @var Preferences $preferences */
        $preferences = $this->preferences->newQuery()->firstOrCreate(
            ['preference_user_id' => $user->id],
            [
                'preference_spy_probes' => 1,
                'preference_planet_sort' => 0,
                'preference_planet_sort_sequence' => 0,
            ]
        );

        return $preferences;
    }

    public function isNicknameChangeAllowed(Preferences $preferences): bool
    {
        return $this->nextNicknameChangeAt($preferences) < time();
    }

    public function nextNicknameChangeAt(Preferences $preferences): int
    {
        return (int) $preferences->preference_nickname_change + ONE_WEEK;
    }

    /**
     * 4 to 20 ASCII letters and digits, like the registration (alpha_num:ascii).
     * Names are output unescaped in many places (messages, galaxy, alliance),
     * so a name like <svg/onload=...> ran script in other players' browsers.
     */
    public function isValidNickname(string $name): bool
    {
        return strlen($name) > 3 && strlen($name) <= 20 && preg_match('/^[a-zA-Z0-9]+$/', $name) === 1;
    }

    /**
     * @return array<int, array{value: int, selected: bool, text: string}>
     */
    public function planetSortOptions(Preferences $preferences): array
    {
        return collect(PrefEnum::ORDER)
            ->map(fn (int $value, string $order) => [
                'value' => $value,
                'selected' => $value === (int) $preferences->preference_planet_sort,
                'text' => __('game/preferences.pr_order_' . $order),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: int, selected: bool, text: string}>
     */
    public function planetSortSequenceOptions(Preferences $preferences): array
    {
        return collect(PrefEnum::SEQUENCE)
            ->map(fn (int $value, string $sequence) => [
                'value' => $value,
                'selected' => $value === (int) $preferences->preference_planet_sort_sequence,
                'text' => __('game/preferences.pr_sorting_sequence_' . $sequence),
            ])
            ->values()
            ->all();
    }

    /**
     * The preference fields of the preferences form, clamped to their ranges.
     *
     * @return array<string, int|null>
     */
    public function formUpdates(Request $request): array
    {
        return [
            'preference_delete_mode' => $request->input('preference_delete_mode') === 'on' ? time() : null,
            'preference_spy_probes' => $this->boundedInteger($request, 'preference_spy_probes', 1, 1, 99),
            'preference_fleet_escape' => $request->input('preference_fleet_escape') === 'on' ? 1 : 0,
            'preference_planet_sort' => $this->boundedInteger(
                $request,
                'preference_planet_sort',
                0,
                0,
                max(PrefEnum::ORDER)
            ),
            'preference_planet_sort_sequence' => $this->boundedInteger(
                $request,
                'preference_planet_sort_sequence',
                0,
                0,
                max(PrefEnum::SEQUENCE)
            ),
        ];
    }

    private function boundedInteger(Request $request, string $key, int $default, int $min, int $max): int
    {
        return min(max($request->integer($key, $default), $min), $max);
    }
}
