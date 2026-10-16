<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Libraries\Functions;
use App\Libraries\UpdatesLibrary;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\TimingService;
use Xgp\App\Core\Enumerators\SwitchIntEnumerator as SwitchInt;
use Xgp\App\Core\Enumerators\UserRanksEnumerator as UserRanks;

/**
 * What runs before every game page: the player's language, the game's time
 * zone, the game tick, and the closed-server and ban checks.
 *
 * This was the legacy bootstrap (Core\Common), which only ran for pages that
 * were still served by the legacy front controller. Once every page was a
 * native controller, only the tick was carried over, so the language, the
 * time zone and both checks were silently skipped.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
final class GameBootstrap
{
    public function __construct(
        private SettingsService $settings,
        private TimingService $timing
    ) {
    }

    public function run(User $user): void
    {
        Functions::setLanguage();
        $this->applyTimezone();
        $this->runUpdates();
        $this->ensureAccess($user);
    }

    /**
     * The game's time zone, if the setting holds a valid one.
     */
    public function applyTimezone(): void
    {
        $timezone = $this->settings->getString('date_time_zone');

        if (in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }
    }

    /**
     * The game tick: fleet arrivals, statistics, cleanup.
     */
    private function runUpdates(): void
    {
        if (!defined('SHIP_DEBRIS_FACTOR')) {
            define('SHIP_DEBRIS_FACTOR', $this->settings->getInt('fleet_cdr') / 100);
        }

        if (!defined('DEFENSE_DEBRIS_FACTOR')) {
            define('DEFENSE_DEBRIS_FACTOR', $this->settings->getInt('defs_cdr') / 100);
        }

        app(UpdatesLibrary::class);
    }

    /**
     * End the request with a message when the server is closed (except for
     * admins) or the player is banned.
     */
    public function ensureAccess(User $user): void
    {
        if ($this->settings->getInt('game_enable') == SwitchInt::OFF && $user->authlevel < UserRanks::ADMIN) {
            Functions::popupMessage($this->settings->getString('close_reason'));
        }

        $ban = $user->ban;

        if ($ban !== null && !$ban->hasExpired()) {
            Functions::popupMessage(sprintf(
                (string) __('game/global.bg_banned'),
                $ban->until !== null ? $this->timing->formatExtendedDate($ban->until) : '∞'
            ));
        }
    }
}
