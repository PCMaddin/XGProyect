<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Support\FleetShips;
use App\Enums\PlayerClass;
use App\Libraries\BattleEngine\Models\Defense;
use App\Libraries\BattleEngine\Models\Fleet;
use App\Libraries\BattleEngine\Models\HomeFleet;
use App\Libraries\BattleEngine\Models\Player;
use App\Libraries\BattleEngine\Models\PlayerGroup;
use App\Libraries\BattleEngine\Models\Ship;
use App\Libraries\BattleEngine\Models\ShipType;

/**
 * Builds the battle engine forces (players, fleets, unit stacks) of a combat
 * mission from fleet rows, user rows and the target planet.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
trait BuildsCombatForces
{
    /** @var array<int, int> hyperspace technology level by player id */
    private array $hyperspaceTechnology = [];

    /** @var array<int, PlayerClass> the class of each player in the battle */
    private array $playerClasses = [];

    /**
     * A ship or defense stack wired from the combat and price config.
     */
    protected function getShipType(int $id, int | float $count): ShipType
    {
        $shield = self::num($this->combatCaps[$id]['shield']);
        $cost = [self::int($this->pricelist[$id]['metal']), self::int($this->pricelist[$id]['crystal'])];
        $power = self::num($this->combatCaps[$id]['attack']);

        if ($id >= self::SHIP_MIN_ID && $id <= self::SHIP_MAX_ID) {
            return new Ship($id, $count, $this->rapidFire($id), $shield, $cost, $power);
        }

        return new Defense($id, $count, $this->rapidFire($id), $shield, $cost, $power);
    }

    /**
     * Units without a rapid-fire table get an empty one (ShipType expects an array).
     *
     * @return array<int, int>
     */
    private function rapidFire(int $id): array
    {
        $table = $this->combatCaps[$id]['sd'] ?? [];
        $rapidFire = [];

        foreach (is_array($table) ? $table : [] as $target => $shots) {
            $rapidFire[(int) $target] = self::int($shots);
        }

        return $rapidFire;
    }

    /**
     * The attacking player of a single (non-ACS) fleet.
     *
     * @param array<string, mixed> $fleetRow
     */
    private function attackingFleet(array $fleetRow): PlayerGroup
    {
        $playerId = self::int($fleetRow['fleet_owner']);
        $fleet = $this->buildFleet($fleetRow);

        $this->hyperspaceTechnology[$playerId] = self::int($fleetRow['research_hyperspace_technology']);

        $player = new Player($playerId, [$fleet]);
        $this->equipPlayer($player, $this->getTechnologiesByUserId($playerId), $fleetRow);

        $playerGroup = new PlayerGroup();
        $playerGroup->addPlayer($player);

        return $playerGroup;
    }

    /**
     * The players of several fleets (ACS attackers, or the fleets stationed at
     * the target), one player per owner with all of their fleets.
     *
     * @param list<array<string, mixed>> $fleetRows
     * @param array<string, mixed>       $targetUser the defending user, whose own row is reused
     */
    private function fleetsGroup(array $fleetRows, array $targetUser = []): PlayerGroup
    {
        $playerGroup = new PlayerGroup();

        foreach ($fleetRows as $fleetRow) {
            $playerId = self::int($fleetRow['fleet_owner']);
            $fleet = $this->buildFleet($fleetRow);
            $player = $playerGroup->getPlayer($playerId);

            if ($player !== false) {
                $player->addFleet($fleet);

                continue;
            }

            $player = new Player($playerId, [$fleet]);
            $this->equipPlayer($player, $this->playerInfo($playerId, $targetUser), $fleetRow);
            $playerGroup->addPlayer($player);
        }

        return $playerGroup;
    }

    /**
     * Add the planet's own ships and defenses to the defending user.
     *
     * @param array<string, mixed> $targetUser
     * @param array<string, mixed> $targetPlanet
     * @param array<string, mixed> $fleetRow
     */
    private function addPlanetForces(PlayerGroup $defenders, array $targetUser, array $targetPlanet, array $fleetRow): void
    {
        $homeFleet = new HomeFleet(self::PLANET_FORCES);

        foreach ([[self::DEFENSE_MIN_ID, self::DEFENSE_MAX_ID], [self::SHIP_MIN_ID, self::SHIP_MAX_ID]] as [$fromId, $toId]) {
            for ($id = $fromId; $id <= $toId; $id++) {
                $column = $this->resource[$id] ?? null;

                if ($column !== null && isset($targetPlanet[$column]) && $targetPlanet[$column] != 0) {
                    $homeFleet->addShipType($this->getShipType($id, self::num($targetPlanet[$column])));
                }
            }
        }

        $targetUserId = self::int($targetUser['id']);
        $defender = $defenders->getPlayer($targetUserId);

        // the planet's forces stay a fleet of their own (id 0), so they can be
        // told apart from the defender's stationed fleets after the battle
        // (OPBE's addDefense() merged them into the defender's first fleet)
        if ($defender !== false) {
            $defender->addFleet($homeFleet);

            return;
        }

        $player = new Player($targetUserId, [$homeFleet]);
        $this->equipPlayer($player, $targetUser, $fleetRow, 'fleet_end_');
        $defenders->addPlayer($player);
    }

    /**
     * @param array<string, mixed> $fleetRow
     */
    private function buildFleet(array $fleetRow): Fleet
    {
        $fleet = new Fleet(self::int($fleetRow['fleet_id']));

        foreach (FleetShips::decode(self::str($fleetRow['fleet_array'])) as $id => $count) {
            if ($id != 0 && $count != 0) {
                $fleet->addShipType($this->getShipType($id, $count));
            }
        }

        return $fleet;
    }

    /**
     * @param array<string, mixed> $targetUser
     *
     * @return array<string, mixed>
     */
    private function playerInfo(int $playerId, array $targetUser): array
    {
        if ($targetUser !== [] && $targetUser['id'] == $playerId) {
            return $targetUser;
        }

        $info = $this->getTechnologiesByUserId($playerId);
        $this->hyperspaceTechnology[$playerId] = self::int($info['research_hyperspace_technology']);

        return $info;
    }

    /**
     * Technologies, name and coordinates (start or end of the fleet row).
     *
     * @param array<string, mixed> $info
     * @param array<string, mixed> $fleetRow
     */
    private function equipPlayer(Player $player, array $info, array $fleetRow, string $coordsPrefix = 'fleet_start_'): void
    {
        // generals fight with 2 more levels of each combat technology
        $class = PlayerClass::fromInt($info['player_class'] ?? 0);
        $this->playerClasses[$player->getId()] = $class;
        $bonus = $class->combatTechBonus();
        $player->setTech(
            self::int($info['research_weapons_technology']) + $bonus,
            self::int($info['research_shielding_technology']) + $bonus,
            self::int($info['research_armour_technology']) + $bonus
        );

        $player->setCoords(
            self::int($fleetRow[$coordsPrefix . 'galaxy']),
            self::int($fleetRow[$coordsPrefix . 'system']),
            self::int($fleetRow[$coordsPrefix . 'planet'])
        );

        $player->setName(self::str($info['name']));
    }
}
