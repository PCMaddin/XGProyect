<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Enums\Module;
use App\Libraries\Messenger\MessagesFormat;
use App\Libraries\Messenger\MessagesOptions;
use App\Libraries\Messenger\Messenger;
use App\Services\SettingsService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Xgp\App\Core\Enumerators\MessagesEnumerator;
use Xgp\App\Core\Template;
use Xgp\App\Helpers\StringsHelper;

/**
 * Grab-bag of static helpers shared by the native controllers and the
 * remaining legacy code. The `exit` calls and boolean flags are legacy
 * request-flow control kept for behavioural parity.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 * @SuppressWarnings("PHPMD.ExitExpression")
 */
abstract class Functions
{
    public static function chronoApplet(string $type, string $ref, int $value, bool $init): string
    {
        $template = $init ? 'scripts.chrono_applet_init' : 'scripts.chrono_applet';

        return Template::render($template, [
            'type' => $type,
            'ref' => $ref,
            'value' => $value,
        ]);
    }

    public static function validEmail(string $address): bool
    {
        return preg_match(
            "/^([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$/ix",
            $address
        ) === 1;
    }

    public static function fleetSpeedFactor(): int
    {
        return (int) (app(SettingsService::class)->getInt('fleet_speed') / 2500);
    }

    public static function message(string $mes, ?string $dest = null, int $time = 3, bool $topnav = true, bool $menu = true, bool $center = true): void
    {
        $middle = [
            'middle1' => $center ? '<div id="content">' : '',
            'middle2' => $center ? '</div>' : '',
        ];

        Template::legacyView(
            'message.view',
            array_merge(
                $middle,
                [
                    'mes' => $mes,
                    'dest' => $dest,
                    'time' => $time,
                    'topnav' => $topnav === true ? null : true,
                    'menu' => $menu === true ? null : true,
                ]
            )
        );
    }

    public static function popupMessage(string $mes, ?string $dest = null, int $time = 3): void
    {
        self::message($mes, $dest, $time, false, false, false);
    }

    public static function isModuleAccesible(Module $module): int
    {
        $modules = explode(';', app(SettingsService::class)->getString('modules'));

        return (int) ($modules[$module->value] ?? 0);
    }

    public static function moduleMessage(int $accessLevel): void
    {
        if ($accessLevel === 0) {
            self::message((string) __('game/global.module_not_accesible'), '', 0, true);
            exit;
        }
    }

    public static function sendMessage(int $to, int $sender, int $time = 0, int $type = 0, string $from = '', string $subject = '', string $message = '', bool $allowHtml = false): void
    {
        $options = new MessagesOptions();
        $options->setTo($to);
        $options->setSender($sender);
        $options->setTime($time);
        $options->setType(self::messageType($type));
        $options->setFrom($from);
        $options->setSubject($subject);

        if ($allowHtml) {
            $options->setMessageFormat(MessagesFormat::HTML);
        }

        $options->setMessageText($message);

        (new Messenger())->sendMessage($options);
    }

    private static function messageType(int $type): int
    {
        return match ($type) {
            0 => MessagesEnumerator::ESPIO,
            1 => MessagesEnumerator::COMBAT,
            2 => MessagesEnumerator::EXP,
            3 => MessagesEnumerator::ALLY,
            4 => MessagesEnumerator::USER,
            default => MessagesEnumerator::GENERAL,
        };
    }

    public static function getDefaultVacationTime(): int
    {
        return time() + (3600 * 24 * VACATION_TIME_FORCED);
    }

    public static function setImage(string $path, string $title = 'img', string $attributes = ''): string
    {
        if ($attributes !== '') {
            $attributes = ' ' . $attributes;
        }

        return '<img src="' . $path . '" title="' . $title . '" border="0"' . $attributes . '>';
    }

    /**
     * @deprecated use laravel redirect or Response
     */
    public static function redirect(string $route): void
    {
        header('location:' . $route);
        exit;
    }

    public static function setLanguage(string $locale = ''): void
    {
        if ($locale === '') {
            $stored = session('locale');
            $locale = is_string($stored) ? $stored : app(SettingsService::class)->getString('lang');
        }

        // force english
        if (!in_array($locale, self::getLanguagesList(), true)) {
            $locale = 'en';
        }

        session(['locale' => $locale]);

        App::setLocale($locale);
    }

    public static function getLanguages(string $currentLang): string
    {
        $options = '';

        foreach (self::getLanguagesList() as $lang) {
            $selected = $currentLang === $lang ? 'selected = selected' : '';
            $options .= '<option ' . $selected . ' value="' . $lang . '">' . $lang . '</option>';
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function getLanguagesList(): array
    {
        $disk = Storage::build([
            'driver' => 'local',
            'root' => lang_path(),
        ]);

        return $disk->directories();
    }

    public static function messageBox(string $title, string $message, string $goto = '', string $button = ' ok ', bool $twoLines = false): void
    {
        Template::legacyView(
            'alliance.message_box',
            [
                'goto' => $goto,
                'title' => $title,
                'oneRow' => !$twoLines,
                'message' => $message,
                'button' => $button,
            ]
        );
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function generatePassword(): string
    {
        return StringsHelper::randomString(16);
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $target
     */
    public static function isCurrentPlanet(array $current, array $target): bool
    {
        return ($current['planet_galaxy'] ?? null) == ($target['planet_galaxy'] ?? null)
            && ($current['planet_system'] ?? null) == ($target['planet_system'] ?? null)
            && ($current['planet_planet'] ?? null) == ($target['planet_planet'] ?? null)
            && ($current['planet_type'] ?? null) == ($target['planet_type'] ?? null);
    }
}
