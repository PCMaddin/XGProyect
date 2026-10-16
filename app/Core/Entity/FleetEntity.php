<?php

declare(strict_types=1);

namespace App\Core\Entity;

use App\Core\Entity;

/**
 * @phpstan-import-type Column from Entity
 *
 * @deprecated v4.0.0 use laravel instead
 */
class FleetEntity extends Entity
{
    /**
     * Get the fleet id
     *
     * @return Column
     */
    public function getFleetId()
    {
        return $this->data['fleet_id'];
    }

    /**
     * Get the fleet owner
     *
     * @return Column
     */
    public function getFleetOwner()
    {
        return $this->data['fleet_owner'];
    }

    /**
     * Get the fleet mission
     *
     * @return Column
     */
    public function getFleetMission()
    {
        return $this->data['fleet_mission'];
    }

    /**
     * Get the fleet amount
     *
     * @return Column
     */
    public function getFleetAmount()
    {
        return $this->data['fleet_amount'];
    }

    /**
     * Get the fleet array
     *
     * @return Column
     */
    public function getFleetArray()
    {
        return $this->data['fleet_array'];
    }

    /**
     * Get the fleet start time
     *
     * @return Column
     */
    public function getFleetStartTime()
    {
        return $this->data['fleet_start_time'];
    }

    /**
     * Get the fleet start galaxy
     *
     * @return Column
     */
    public function getFleetStartGalaxy()
    {
        return $this->data['fleet_start_galaxy'];
    }

    /**
     * Get the fleet start system
     *
     * @return Column
     */
    public function getFleetStartSystem()
    {
        return $this->data['fleet_start_system'];
    }

    /**
     * Get the fleet start planet
     *
     * @return Column
     */
    public function getFleetStartPlanet()
    {
        return $this->data['fleet_start_planet'];
    }

    /**
     * Get the fleet start type
     *
     * @return Column
     */
    public function getFleetStartType()
    {
        return $this->data['fleet_start_type'];
    }

    /**
     * Get the fleet end time
     *
     * @return Column
     */
    public function getFleetEndTime()
    {
        return $this->data['fleet_end_time'];
    }

    /**
     * Get the fleet end stay
     *
     * @return Column
     */
    public function getFleetEndStay()
    {
        return $this->data['fleet_end_stay'];
    }

    /**
     * Get the fleet end galaxy
     *
     * @return Column
     */
    public function getFleetEndGalaxy()
    {
        return $this->data['fleet_end_galaxy'];
    }

    /**
     * Get the fleet end system
     *
     * @return Column
     */
    public function getFleetEndSystem()
    {
        return $this->data['fleet_end_system'];
    }

    /**
     * Get the fleet end planet
     *
     * @return Column
     */
    public function getFleetEndPlanet()
    {
        return $this->data['fleet_end_planet'];
    }

    /**
     * Get the fleet end type
     *
     * @return Column
     */
    public function getFleetEndType()
    {
        return $this->data['fleet_end_type'];
    }

    /**
     * Get the fleet target obj
     *
     * @return Column
     */
    public function getFleetTargetObj()
    {
        return $this->data['fleet_target_obj'];
    }

    /**
     * Get the fleet resource metal
     *
     * @return Column
     */
    public function getFleetResourceMetal()
    {
        return $this->data['fleet_resource_metal'];
    }

    /**
     * Get the fleet resource crystal
     *
     * @return Column
     */
    public function getFleetResourceCrystal()
    {
        return $this->data['fleet_resource_crystal'];
    }

    /**
     * Get the fleet resource deuterium
     *
     * @return Column
     */
    public function getFleetResourceDeuterium()
    {
        return $this->data['fleet_resource_deuterium'];
    }

    /**
     * Get the fleet fuel
     *
     * @return Column
     */
    public function getFleetFuel()
    {
        return $this->data['fleet_fuel'];
    }

    /**
     * Get the fleet target owner
     *
     * @return Column
     */
    public function getFleetTargetOwner()
    {
        return $this->data['fleet_target_owner'];
    }

    /**
     * Get the fleet group
     *
     * @return Column
     */
    public function getFleetGroup()
    {
        return $this->data['fleet_group'];
    }

    /**
     * Get the fleet mess
     *
     * @return Column
     */
    public function getFleetMess()
    {
        return $this->data['fleet_mess'];
    }

    /**
     * Get the fleet creation
     *
     * @return Column
     */
    public function getFleetCreation()
    {
        return $this->data['fleet_creation'];
    }
}
