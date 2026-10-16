<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Models\Preferences;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The account part of the preferences form: new name, password and email,
 * each confirmed with the current password.
 */
class AccountUpdateService
{
    public function __construct(
        private PreferencesService $preferencesService,
    ) {
    }

    /**
     * Validate the account changes of the form and save them together with
     * the preference updates.
     *
     * @param array<string, mixed> $preferenceUpdates
     *
     * @return string the first error, '' when saved
     */
    public function save(Request $request, User $user, Preferences $preferences, array $preferenceUpdates): string
    {
        /** @var Collection<string, mixed> $userUpdates */
        $userUpdates = collect();
        $preferenceChanges = collect($preferenceUpdates);

        $errors = [
            $this->validateNewUserName($request, $user, $preferences, $userUpdates, $preferenceChanges),
            $this->validateNewPassword($request, $user, $userUpdates),
            $this->validateNewEmail($request, $user, $userUpdates),
        ];

        foreach ($errors as $error) {
            if ($error !== null) {
                return $error;
            }
        }

        DB::transaction(function () use ($user, $preferences, $userUpdates, $preferenceChanges): void {
            if ($userUpdates->isNotEmpty()) {
                $userUpdates->each(fn (mixed $value, string $key) => $user->setAttribute($key, $value));
                $user->save();
            }

            if ($preferenceChanges->isNotEmpty()) {
                $preferences->fill($preferenceChanges->all());
                $preferences->save();
            }
        });

        return '';
    }

    /**
     * Bugfix: only the length was checked here, so the preferences page let
     * through the names with markup that the nickname page rejects.
     *
     * @param Collection<string, mixed> $userUpdates
     * @param Collection<string, mixed> $preferenceUpdates
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    private function validateNewUserName(
        Request $request,
        User $user,
        Preferences $preferences,
        Collection $userUpdates,
        Collection $preferenceUpdates,
    ): ?string {
        $newUserName = $this->filledString($request, 'new_user_name');
        $password = $this->filledString($request, 'confirmation_user_password');

        if ($newUserName === null || $password === null || !$this->preferencesService->isNicknameChangeAllowed($preferences)) {
            return null;
        }

        if (!Hash::check($password, $user->password)) {
            return $this->translation('game/preferences.pr_error_wrong_password');
        }

        if (!$this->preferencesService->isValidNickname($newUserName)) {
            return strtr(
                $this->translation('game/preferences.pr_error_user_invalid_characters'),
                ['%s' => $newUserName]
            );
        }

        $nameExists = User::where('name', $newUserName)
            ->where('id', '<>', $user->id)
            ->exists();

        if ($nameExists) {
            return $this->translation('game/preferences.pr_error_nick_in_use');
        }

        $userUpdates->put('name', $newUserName);
        $preferenceUpdates->put('preference_nickname_change', time());

        return null;
    }

    /**
     * @param Collection<string, mixed> $userUpdates
     */
    private function validateNewPassword(Request $request, User $user, Collection $userUpdates): ?string
    {
        $currentPassword = $this->filledString($request, 'current_user_password');
        $newPassword = $this->filledString($request, 'new_user_password');

        if ($currentPassword === null || $newPassword === null) {
            return null;
        }

        if (!Hash::check($currentPassword, $user->password)) {
            return $this->translation('game/preferences.pr_error_wrong_password');
        }

        $userUpdates->put('password', $newPassword);

        return null;
    }

    /**
     * @param Collection<string, mixed> $userUpdates
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    private function validateNewEmail(Request $request, User $user, Collection $userUpdates): ?string
    {
        $newEmail = $this->filledString($request, 'new_user_email');
        $password = $this->filledString($request, 'confirmation_email_password');

        if ($newEmail === null || $password === null) {
            return null;
        }

        if (!Hash::check($password, $user->password)) {
            return $this->translation('game/preferences.pr_error_wrong_password');
        }

        $emailLength = strlen($newEmail);

        if ($emailLength <= 4 || $emailLength > 64 || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            return strtr(
                $this->translation('game/preferences.pr_error_email_invalid_characters'),
                ['%s' => $newEmail]
            );
        }

        $emailExists = User::where('email', $newEmail)
            ->where('id', '<>', $user->id)
            ->exists();

        if ($emailExists) {
            return $this->translation('game/preferences.pr_error_email_in_use');
        }

        $userUpdates->put('email', $newEmail);

        return null;
    }

    private function filledString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function translation(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : '';
    }
}
