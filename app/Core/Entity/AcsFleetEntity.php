<?php

declare(strict_types=1);

namespace App\Core\Entity;

use App\Core\Entity;

/**
 * @phpstan-import-type Column from Entity
 *
 * @deprecated v4.0.0 use laravel instead
 */
class AcsFleetEntity extends Entity
{
    /**
     * Get the acs id
     *
     * @return Column
     */
    public function getAcsFleetId()
    {
        return $this->data['acs_id'];
    }

    /**
     * Get the acs name
     *
     * @return Column
     */
    public function getAcsFleetName()
    {
        return $this->data['acs_name'];
    }

    /**
     * Get the acs owner
     *
     * @return Column
     */
    public function getAcsFleetOwner()
    {
        return $this->data['acs_owner'];
    }

    /**
     * Get the acs galaxy
     *
     * @return Column
     */
    public function getAcsFleetGalaxy()
    {
        return $this->data['acs_galaxy'];
    }

    /**
     * Get the acs system
     *
     * @return Column
     */
    public function getAcsFleetSystem()
    {
        return $this->data['acs_system'];
    }

    /**
     * Get the acs planet
     *
     * @return Column
     */
    public function getAcsFleetPlanet()
    {
        return $this->data['acs_planet'];
    }

    /**
     * Get the acs planet type
     *
     * @return Column
     */
    public function getAcsFleetPlanetType()
    {
        return $this->data['acs_planet_type'];
    }
}
