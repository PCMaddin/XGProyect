<?php

declare(strict_types=1);

namespace App\Services\Game\Formulas;

use App\Support\OfficerBonuses;

class OfficerService
{
    public function isOfficerActive(int $expireTime, int $currentTime): bool
    {
        return $expireTime > $currentTime;
    }

    /**
     * The espionage level with the technocrat (and the commanding staff).
     */
    public function getMaxEspionage(int $espionageTech, bool | OfficerBonuses $officers): int
    {
        if ($officers instanceof OfficerBonuses) {
            return $espionageTech + $officers->espionageBonus();
        }

        return $espionageTech + ($officers ? TECHNOCRATE_SPY : 0);
    }

    public function getMaxComputer(int $computerTech, bool $admiralActive): int
    {
        return 1 + $computerTech + ($admiralActive ? AMIRAL : 0);
    }

    /**
     * The officers of a row with the premium_officier_* expiry times.
     *
     * @param array<string, mixed> $row
     */
    public function bonuses(array $row, int $now): OfficerBonuses
    {
        return OfficerBonuses::fromRow($row, $now);
    }

    public function getDaysLeft(int $expireTime, int $currentTime): float
    {
        return ($expireTime - $currentTime) / 86400;
    }
}
