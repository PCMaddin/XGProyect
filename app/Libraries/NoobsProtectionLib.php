<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;

/**
 * Noob-protection rules: whether an attacker is too weak or too strong to hit a
 * target given the point spread and the configured protection settings.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class NoobsProtectionLib
{
    use PreparesLegacySql;

    private bool $protection;

    private int $protectionTime;

    private int $protectionMulti;

    private int $allowedLevel;

    public function __construct()
    {
        $settings = app(SettingsService::class);

        $this->protection = $settings->getBool('noobprotection');
        $this->protectionTime = $settings->getInt('noobprotectiontime');
        $this->protectionMulti = $settings->getInt('noobprotectionmulti');
        $this->allowedLevel = $settings->getInt('stat_admin_level');
    }

    public function isWeak(int $currentPoints, int $otherPoints): bool
    {
        if (!$this->protection) {
            return false;
        }

        $multi = $this->protectionMulti === 0 ? 1 : $this->protectionMulti;

        if ($currentPoints > $otherPoints * $multi) {
            return !($otherPoints > $this->protectionTime && $this->protectionTime > 0);
        }

        return false;
    }

    public function isStrong(int $currentPoints, int $otherPoints): bool
    {
        if (!$this->protection) {
            return false;
        }

        $multi = $this->protectionMulti === 0 ? 1 : $this->protectionMulti;

        if ($currentPoints * $multi < $otherPoints) {
            return !($currentPoints > $this->protectionTime && $this->protectionTime > 0);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function returnPoints(int $currentUserId, int $otherUserId): array
    {
        $row = DB::selectOne(
            $this->prepareSql(
                'SELECT
                    (SELECT `user_statistic_total_points` FROM `' . USERS_STATISTICS . '`
                        WHERE `user_statistic_user_id` = ?) AS user_points,
                    (SELECT `user_statistic_total_points` FROM `' . USERS_STATISTICS . '`
                        WHERE `user_statistic_user_id` = ?) AS target_points;'
            ),
            [$currentUserId, $otherUserId]
        );

        return is_object($row) ? get_object_vars($row) : [];
    }

    public function isRankVisible(int $userAuthLevel): bool
    {
        return $userAuthLevel <= $this->allowedLevel;
    }
}
