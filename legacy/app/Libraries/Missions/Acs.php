<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

class Acs extends Missions
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * ACS Attack - attack between several players
     *
     * @param array<string, mixed> $fleet
     */
    public function acsMission(array $fleet): void
    {
        // do mission
        if (parent::canStartMission($fleet)) {
            parent::returnFleet(self::int($fleet['fleet_id']));
        }

        // complete mission
        if (parent::canCompleteMission($fleet)) {
            // transfer the ships to the planet
            parent::restoreFleet($fleet);
            parent::removeFleet(self::int($fleet['fleet_id']));
        }
    }
}
