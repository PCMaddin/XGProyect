<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\Planets;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\PlanetTypesEnumerator;

/**
 * Creates the planet / moon rows for new colonies. Ported from the legacy
 * class; the moon lookup now guards the (possibly missing) row instead of
 * dereferencing null, and both inserts are bound.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 */
class PlanetLib
{
    use PreparesLegacySql;

    public function setNewPlanet(int $galaxy, int $system, int $position, int $owner, string $name = '', bool $main = false): bool
    {
        $planetExists = Planets::where([
            'planet_galaxy' => $galaxy,
            'planet_system' => $system,
            'planet_planet' => $position,
        ])->first() !== null;

        if ($planetExists) {
            return false;
        }

        $planet = Formulas::getPlanetSize($position, $main);
        $temp = Formulas::setPlanetTemp($position);
        $name = $name === '' ? (string) __('game/global.colony') : $name;

        if ($main) {
            $name = (string) __('game/global.homeworld');
        }

        $settings = app(SettingsService::class);
        $newPlanet = Planets::create([
            'planet_name' => $name,
            'planet_user_id' => $owner,
            'planet_galaxy' => $galaxy,
            'planet_system' => $system,
            'planet_planet' => $position,
            'planet_last_update' => time(),
            'planet_type' => PlanetTypesEnumerator::PLANET,
            'planet_image' => Formulas::setPlanetImage($system, $position),
            'planet_diameter' => $planet['planet_diameter'],
            'planet_field_max' => $planet['planet_field_max'],
            'planet_temp_min' => $temp['min'],
            'planet_temp_max' => $temp['max'],
            'planet_metal' => BUILD_METAL,
            'planet_metal_perhour' => $settings->getInt('metal_basic_income'),
            'planet_crystal' => BUILD_CRISTAL,
            'planet_crystal_perhour' => $settings->getInt('crystal_basic_income'),
            'planet_deuterium' => BUILD_DEUTERIUM,
            'planet_deuterium_perhour' => $settings->getInt('deuterium_basic_income'),
            'planet_b_building_id' => '0',
            'planet_b_hangar_id' => '',
        ]);

        $newPlanet->buildings()->create();
        $newPlanet->defenses()->create();
        $newPlanet->ships()->create();

        return true;
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function setNewMoon(int $galaxy, int $system, int $position, int $owner, string $name = '', int $chance = 0, int $size = 0, int $maxFields = 1, int $minTemp = 0, int $maxTemp = 0): bool
    {
        $moonRow = DB::selectOne(
            $this->prepareSql(
                'SELECT pm2.`planet_id`,
                    pm2.`planet_name`,
                    pm2.`planet_temp_max`,
                    pm2.`planet_temp_min`,
                    (
                        SELECT pm.`planet_id`
                        FROM `' . PLANETS . '` AS pm
                        WHERE pm.`planet_galaxy` = ? AND pm.`planet_system` = ?
                            AND pm.`planet_planet` = ? AND pm.`planet_type` = 3
                    ) AS `id_moon`
                FROM `' . PLANETS . '` AS pm2
                WHERE pm2.`planet_galaxy` = ? AND pm2.`planet_system` = ? AND pm2.`planet_planet` = ?;'
            ),
            [$galaxy, $system, $position, $galaxy, $system, $position]
        );

        $moonPlanet = $moonRow !== null ? (array) $moonRow : [];
        $idMoon = $moonPlanet['id_moon'] ?? '';
        $planetIdRaw = $moonPlanet['planet_id'] ?? 0;
        $existingMoon = is_scalar($idMoon) ? (string) $idMoon : '';
        $planetId = is_numeric($planetIdRaw) ? (int) $planetIdRaw : 0;

        if ($existingMoon !== '' || $planetId === 0) {
            return false;
        }

        $this->createMoon($this->buildMoonData($galaxy, $system, $position, $owner, $name, $chance, $size, $maxFields, $minTemp, $maxTemp));

        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    private function buildMoonData(int $galaxy, int $system, int $position, int $owner, string $name, int $chance, int $size, int $maxFields, int $minTemp, int $maxTemp): array
    {
        $temp = Formulas::setPlanetTemp($position);
        $diameter = $chance === 0 ? $size : mt_rand(2000 + ($chance * 100), 6000 + ($chance * 200));
        $diameter = $diameter === 0 ? mt_rand(2000, 6000) : $diameter;

        return [
            'planet_name' => $name === '' ? (string) __('game/global.moon') : $name,
            'planet_user_id' => $owner,
            'planet_galaxy' => $galaxy,
            'planet_system' => $system,
            'planet_planet' => $position,
            'planet_last_update' => time(),
            'planet_type' => PlanetTypesEnumerator::MOON,
            'planet_image' => 'mond',
            'planet_diameter' => $diameter,
            'planet_field_max' => $maxFields === 0 ? 1 : $maxFields,
            'planet_temp_min' => $minTemp === 0 ? $temp['min'] : $minTemp,
            'planet_temp_max' => $maxTemp === 0 ? $temp['max'] : $maxTemp,
            'planet_b_building_id' => '0',
            'planet_b_hangar_id' => '',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createMoon(array $data): void
    {
        $columns = array_keys($data);
        $columnList = '`' . implode('`, `', $columns) . '`';
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        DB::insert(
            $this->prepareSql('INSERT INTO `' . PLANETS . '` (' . $columnList . ') VALUES (' . $placeholders . ');'),
            array_values($data)
        );

        $newMoonId = (int) DB::getPdo()->lastInsertId();

        DB::insert($this->prepareSql('INSERT INTO `' . BUILDINGS . '` (`building_planet_id`) VALUES (?);'), [$newMoonId]);
        DB::insert($this->prepareSql('INSERT INTO `' . DEFENSES . '` (`defense_planet_id`) VALUES (?);'), [$newMoonId]);
        DB::insert($this->prepareSql('INSERT INTO `' . SHIPS . '` (`ship_planet_id`) VALUES (?);'), [$newMoonId]);
    }
}
