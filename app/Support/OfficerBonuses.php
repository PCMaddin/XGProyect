<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The officers a player has hired, and their bonuses (owiki "Kommandostab"):
 *
 * - admiral: +2 fleet slots, +1 expedition, fleets flee at 3:1 instead of
 *   5:1, a plunder priority;
 * - engineer: +10 % energy, half the losses of defenses;
 * - geologist: +10 % mines;
 * - technocrat: +2 espionage levels (and 25 % less research time);
 * - all five (commanding staff): +1 fleet slot, +2 % energy, +2 % mines,
 *   +1 espionage level more.
 *
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 */
final class OfficerBonuses
{
    private const STAFF_SLOT = 1;
    private const STAFF_PRODUCTION = 0.02;
    private const STAFF_ESPIONAGE = 1;

    public function __construct(
        public readonly bool $commander = false,
        public readonly bool $admiral = false,
        public readonly bool $engineer = false,
        public readonly bool $geologist = false,
        public readonly bool $technocrat = false,
    ) {
    }

    /**
     * From a row with the premium_officier_* expiry times.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, int $now): self
    {
        $active = function (string $officer) use ($row, $now): bool {
            $until = $row['premium_officier_' . $officer] ?? 0;

            return is_numeric($until) && (int) $until > $now;
        };

        return new self($active('commander'), $active('admiral'), $active('engineer'), $active('geologist'), $active('technocrat'));
    }

    public function commandingStaff(): bool
    {
        return $this->commander && $this->admiral && $this->engineer && $this->geologist && $this->technocrat;
    }

    public function extraFleetSlots(): int
    {
        return ($this->admiral ? AMIRAL : 0) + ($this->commandingStaff() ? self::STAFF_SLOT : 0);
    }

    public function extraExpeditions(): int
    {
        return $this->admiral ? 1 : 0;
    }

    public function mineBonus(): float
    {
        return ($this->geologist ? GEOLOGUE : 0) + ($this->commandingStaff() ? self::STAFF_PRODUCTION : 0);
    }

    public function energyBonus(): float
    {
        return ($this->engineer ? ENGINEER_ENERGY : 0) + ($this->commandingStaff() ? self::STAFF_PRODUCTION : 0);
    }

    public function espionageBonus(): int
    {
        return ($this->technocrat ? TECHNOCRATE_SPY : 0) + ($this->commandingStaff() ? self::STAFF_ESPIONAGE : 0);
    }

    /** the engineer halves the losses of defenses */
    public function defenseLossFactor(): float
    {
        return $this->engineer ? 0.5 : 1.0;
    }
}
