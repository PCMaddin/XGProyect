<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AllianceClass;
use App\Enums\PlayerClass;

/**
 * The bonuses of a player's class and of their alliance's class together.
 *
 * Alliance classes (OGame 7): traders fly cargo ships 10 % faster, produce
 * 5 % more resources and energy and store 10 % more; researchers colonize
 * 5 % larger planets and fly expeditions 10 % faster; warriors fight with 1
 * more level of each combat technology and of espionage, and fly 10 %
 * faster to alliance members.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
final class ClassBonuses
{
    public function __construct(
        public readonly PlayerClass $player = PlayerClass::None,
        public readonly AllianceClass $alliance = AllianceClass::None,
    ) {
    }

    public function speedFactor(int $shipId): float
    {
        $trader = $this->alliance === AllianceClass::Trader && in_array($shipId, PlayerClass::CARGO_SHIPS, true) ? 1.1 : 1.0;

        return $this->player->speedFactor($shipId) * $trader;
    }

    public function cargoBonus(int $shipId): float
    {
        return $this->player->cargoBonus($shipId);
    }

    public function consumptionFactor(): float
    {
        return $this->player->consumptionFactor();
    }

    public function extraFleetSlots(): int
    {
        return $this->player->extraFleetSlots();
    }

    public function extraExpeditions(): int
    {
        return $this->player->extraExpeditions();
    }

    public function combatTechBonus(): int
    {
        return $this->player->combatTechBonus() + ($this->alliance === AllianceClass::Warrior ? 1 : 0);
    }

    public function espionageBonus(): int
    {
        return $this->alliance === AllianceClass::Warrior ? 1 : 0;
    }

    public function mineProductionBonus(): float
    {
        return $this->player->mineProductionBonus() + ($this->alliance === AllianceClass::Trader ? 0.05 : 0.0);
    }

    public function energyProductionBonus(): float
    {
        return $this->player->energyProductionBonus() + ($this->alliance === AllianceClass::Trader ? 0.05 : 0.0);
    }

    public function storageFactor(): float
    {
        return $this->alliance === AllianceClass::Trader ? 1.1 : 1.0;
    }

    public function planetSizeFactor(): float
    {
        return $this->player->planetSizeFactor() * ($this->alliance === AllianceClass::Researcher ? 1.05 : 1.0);
    }

    /** researchers fly expeditions 10 % faster */
    public function expeditionSpeedFactor(): float
    {
        return $this->alliance === AllianceClass::Researcher ? 1.1 : 1.0;
    }

    /** warriors fly 10 % faster to members of their alliance */
    public function allySpeedFactor(): float
    {
        return $this->alliance === AllianceClass::Warrior ? 1.1 : 1.0;
    }
}
