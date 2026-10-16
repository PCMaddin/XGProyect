<?php

declare(strict_types=1);

namespace App\Libraries\Missions;

use App\Core\GameObjects\GameObject;
use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Officer;
use App\Core\GameObjects\Ship;

/**
 * The game object config (names, prices, combat specs) by object id, in the
 * array shapes the mission handlers and the battle engine work with.
 */
final class MissionObjects
{
    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return self::registry()->names();
    }

    /**
     * Prices and factor; ships also with consumption, speed and capacity,
     * officers with their dark matter prices and images.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function prices(): array
    {
        $prices = [];

        foreach (self::registry()->all() as $id => $object) {
            if ($object instanceof Officer) {
                $prices[$id] = [
                    'darkmatter_week' => $object->getDarkmatterWeek(),
                    'darkmatter_month' => $object->getDarkmatterMonth(),
                    'img_big' => $object->getImgBig(),
                    'img_small' => $object->getImgSmall(),
                ];

                continue;
            }

            if (!$object instanceof GameObject) {
                continue;
            }

            $prices[$id] = $object->getPrice()->toArray();

            if ($object instanceof Ship) {
                $prices[$id] += [
                    'consumption' => $object->getConsumption(),
                    'consumption2' => $object->getConsumption2(),
                    'speed' => $object->getSpeed(),
                    'speed2' => $object->getSpeed2(),
                    'capacity' => $object->getCapacity(),
                ];
            }
        }

        return $prices;
    }

    /**
     * Shield, attack and rapid fire ('sd': target id => shots) of ships and
     * defenses; defenses without rapid fire have no 'sd'.
     *
     * @return array<int, array{shield: float, attack: float, sd?: array<int, int>}>
     */
    public static function combatSpecs(): array
    {
        $specs = [];

        foreach (self::registry()->ships() as $id => $ship) {
            $specs[$id] = [
                'shield' => $ship->getShield(),
                'attack' => $ship->getAttack(),
                'sd' => $ship->getRapidFire()->all(),
            ];
        }

        foreach (self::registry()->defenses() as $id => $defense) {
            $specs[$id] = [
                'shield' => $defense->getShield(),
                'attack' => $defense->getAttack(),
            ];

            if ($defense->getRapidFire()->isNotEmpty()) {
                $specs[$id]['sd'] = $defense->getRapidFire()->all();
            }
        }

        return $specs;
    }

    private static function registry(): GameObjectRegistry
    {
        return app(GameObjectRegistry::class);
    }
}
