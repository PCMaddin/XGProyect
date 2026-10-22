<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Libraries\BattleEngine\Utils\Lang;
use App\Libraries\BattleEngine\Utils\LangManager;

class AttackLang implements Lang
{
    /** @var array<int, string> */
    private array $objects;

    /**
     * @param array<int, string> $objects object names by object id
     */
    public function __construct(array $objects)
    {
        $this->objects = $objects;
    }

    /**
     * Use the game translations for the battle reports.
     *
     * @param array<int, string> $objects object names by object id
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public static function activate(array $objects): void
    {
        LangManager::getInstance()->setImplementation(new self($objects));
    }

    public function getShipName(int $id): string
    {
        $objectName = $this->objects[$id];
        $translationGroup = str_starts_with($objectName, 'defense_') ? 'game/defenses.' : 'game/ships.';

        return __($translationGroup . $objectName);
    }

    public function getAttackersAttackingDescr(string | int $amount, string | int $damage): string
    {
        return sprintf(__('game/combatreport.cr_fleet_attack_1'), $amount, $damage);
    }

    public function getDefendersDefendingDescr(string | int $damage): string
    {
        return sprintf(__('game/combatreport.cr_fleet_attack_2'), $damage);
    }

    public function getDefendersAttackingDescr(string | int $amount, string | int $damage): string
    {
        return sprintf(__('game/combatreport.cr_fleet_defs_1'), $amount, $damage);
    }

    public function getAttackersDefendingDescr(string | int $damage): string
    {
        return sprintf(__('game/combatreport.cr_fleet_defs_2'), $damage);
    }

    public function getTechs(int $weaponsTech, int $shieldsTech, int $armourTech): string
    {
        return sprintf(__('game/combatreport.cr_technologies'), ($weaponsTech * 10), ($shieldsTech * 10), ($armourTech * 10));
    }

    public function getAttackerHasWon(): string
    {
        return __('game/combatreport.cr_attacker_won');
    }

    public function getDefendersHasWon(): string
    {
        return __('game/combatreport.cr_defender_won');
    }

    public function getDraw(): string
    {
        return __('game/combatreport.cr_both_won');
    }

    public function getStoleDescr(string | int $metal, string | int $crystal, string | int $deuterium): string
    {
        return sprintf(__('game/combatreport.cr_stealed_ressources'), $metal, $crystal, $deuterium);
    }

    public function getAttackersLostUnits(string | int $units): string
    {
        return sprintf(__('game/combatreport.cr_attacker_lostunits'), $units);
    }

    public function getDefendersLostUnits(string | int $units): string
    {
        return sprintf(__('game/combatreport.cr_defender_lostunits'), $units);
    }

    public function getFloatingDebris(string | int $metal, string | int $crystal): string
    {
        return sprintf(__('game/combatreport.cr_debris_units'), $metal, $crystal);
    }

    public function getMoonProb(int $prob): string
    {
        return sprintf(__('game/combatreport.cr_moonproba'), $prob);
    }

    public function getNewMoon(string $name, int $galaxy, int $system, int $planet): string
    {
        return sprintf(__('game/combatreport.cr_moonbuilt'), $name, $galaxy, $system, $planet);
    }
}
