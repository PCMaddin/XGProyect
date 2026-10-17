<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The alliance classes of OGame 7 (OGame wiki, "Trader", "Researcher",
 * "Warrior"); their bonuses apply to every member.
 */
enum AllianceClass: int
{
    case None = 0;
    case Trader = 1;
    case Researcher = 2;
    case Warrior = 3;

    public static function fromInt(mixed $value): self
    {
        return self::tryFrom(is_numeric($value) ? (int) $value : 0) ?? self::None;
    }

    public function nameKey(): string
    {
        return 'al_class_' . strtolower($this->name);
    }
}
